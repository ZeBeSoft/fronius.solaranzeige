#!/usr/bin/php
<?php
/*****************************************************************************
//  Solaranzeige Projekt             Copyright (C) [2015-2020]  [Ulrich Kunz]
//
//  Dieses Programm ist freie Software. Sie können es unter den Bedingungen
//  der GNU General Public License, wie von der Free Software Foundation
//  veröffentlicht, weitergeben und/oder modifizieren, entweder gemäß
//  Version 3 der Lizenz oder (nach Ihrer Option) jeder späteren Version.
//
//  Die Veröffentlichung dieses Programms erfolgt in der Hoffnung, daß es
//  Ihnen von Nutzen sein wird, aber OHNE IRGENDEINE GARANTIE, sogar ohne
//  die implizite Garantie der MARKTREIFE oder der VERWENDBARKEIT FÜR EINEN
//  BESTIMMTEN ZWECK. Details finden Sie in der GNU General Public License.
//
//  Ein original Exemplar der GNU General Public License finden Sie hier:
//  http://www.gnu.org/licenses/
//
//  Dies ist ein Programmteil des Programms "Solaranzeige"
//
//  Es dient dem Auslesen des Victron-energy Reglers über die USB Schnittstelle
//  Das Auslesen wird hier mit einer Schleife durchgeführt. Wie oft die Daten
//  ausgelesen und gespeichert werden steht in der user.config.php
//
//  Achtung! Der Regler sendet zwischendurch immer wieder asynchrone Daten!
//
*****************************************************************************/
$path_parts = pathinfo($argv[0]);
$Pfad = $path_parts['dirname'];
if (!is_file($Pfad."/1.user.config.php")) {
  // Handelt es sich um ein Multi Regler System?
  require($Pfad."/user.config.php");
}

require_once($Pfad."/phpinc/funktionen.inc.php");
if (!isset($funktionen)) {
  $funktionen = new funktionen();
}
// Im Fall, dass man die Device manuell eingeben muss
if (isset($USBDevice) and !empty($USBDevice)) {
  $USBRegler = $USBDevice;
}

$Tracelevel = 9;  //  1 bis 10  10 = Debug
$aktuelleDaten = array();
$Version = "";
$Device = "LR"; // LR = Laderegler
$Start = time();  // Timestamp festhalten
$funktionen->log_schreiben("-------------   Start  ivt_solarregler.php   ----------------- ","|--",6);
setlocale(LC_TIME,"de_DE.utf8");
$RemoteDaten = true;

//  Hardware Version ermitteln.
$Teile =  explode(" ",$Platine);
if ($Teile[1] == "Pi") {
  $Version = trim($Teile[2]);
  if ($Teile[3] == "Model") {
    $Version .= trim($Teile[4]);
    if ($Teile[5] == "Plus") {
      $Version .= trim($Teile[5]);
    }
  }
}
$funktionen->log_schreiben("Hardware Version: ".$Version,"o  ",8);

switch($Version) {
  case "2B":
  break;
  case "3B":
  break;
  case "3BPlus":
  break;
  case "4B":
  break;
  default:
  break;
}

/*****************************************************************************
//  Die Status Datei wird dazu benutzt, um die Leistung des Reglers
//  pro Tag zu speichern.
//
*****************************************************************************/
$StatusFile = $Pfad."/database/".$GeraeteNummer.".WhProTag.txt";
if (!file_exists($StatusFile)) {
  /***************************************************************************
  //  Inhalt der Status Datei anlegen, wenn nicht existiert.
  ***************************************************************************/
  $rc = file_put_contents($StatusFile,"0");
  if ($rc === false) {
    $funktionen->log_schreiben("Konnte die Datei whProTag_ivt.txt nicht anlegen.",5);
  }
}
else {
  $aktuelleDaten["WattstundenGesamtGestern"] = file_get_contents($StatusFile);
  $funktionen->log_schreiben("WattstundenGesamtGestern: ".$aktuelleDaten["WattstundenGesamtGestern"],"   ",8);
}


$USB1 = $funktionen->openUSB($USBRegler);
if (!is_resource($USB1)) {
  $funktionen->log_schreiben("USB Port kann nicht geöffnet werden. [1]","XX ",7);
  $funktionen->log_schreiben("Exit.... ","XX ",7);
  goto Ausgang;
}

$i = 1;
do {
  $funktionen->log_schreiben("Die Daten werden ausgelesen...","+  ",9);

  /****************************************************************************
  //  Ab hier wird der Regler ausgelesen.
  //
  //  Ergebniswerte:
  //  $aktuelleDaten["Firmware"]                Nummer
  //  $aktuelleDaten["Produkt"]                 Text
  //  $aktuelleDaten["Objekt"]                  Text
  //  $aktuelleDaten["Batteriespannung"]
  //  $aktuelleDaten["Batterieentladestrom"]
  //  $aktuelleDaten["Batterieladestrom"]
  //  $aktuelleDaten["Ladestatus"]
  //  $aktuelleDaten["Solarstrom"]
  //  $aktuelleDaten["Solarspannung"]
  //  $aktuelleDaten["Solarleistung"]
  //  $aktuelleDaten["WattstundenGesamt"]
  //  $aktuelleDaten["WattstundenGesamtHeute"]
  //  $aktuelleDaten["WattstundenGesamtGestern"]
  //  $aktuelleDaten["maxWattHeute"]
  //  $aktuelleDaten["maxAmpHeute"]
  //  $aktuelleDaten["Temperatur"]
  //  $aktuelleDaten["Optionen"]
  //  $aktuelleDaten["ErrorCodes"]
  //
  ****************************************************************************/

  $Ergebnis = $funktionen->ivt_lesen($USB1,'pc.solar.gettype');
  $funktionen->log_schreiben("Hardware Version: ".hexdec(substr(bin2hex($Ergebnis),0,2))." Volt / ".hexdec(substr(bin2hex($Ergebnis),-2))." Ampere","   ",9);

  $aktuelleDaten["Firmware"] = 0;
  $aktuelleDaten["Produkt"] = "SC ".hexdec(substr(bin2hex($Ergebnis),0,2))." V / ".hexdec(substr(bin2hex($Ergebnis),-2))." A";

  $funktionen->log_schreiben(bin2hex($Ergebnis),"   ",9);

  $Ergebnis = $funktionen->ivt_lesen($USB1);  //  USB Speicher löschen

  $Ergebnis = $funktionen->ivt_lesen($USB1,'pc.solar.getdata');

  if ($Ergebnis === false) {
    $funktionen->log_schreiben("Daten waren falsch oder unvollständig. 1 Sekunde warten.","   ",6);
    if ($i > 2) {
      break;
    }
    $i++;
    sleep(1);
    continue;
  }

  $funktionen->log_schreiben(bin2hex($Ergebnis),"   ",9);

  $DatenArray = $funktionen->ivt_entschluesseln($Ergebnis);


  $aktuelleDaten["Batteriespannung"] = $DatenArray["BatVL"].".".$DatenArray["BatVR"];
  $aktuelleDaten["Solarspannung"] = $DatenArray["SolarVL"].".".$DatenArray["SolarVR"];
  $aktuelleDaten["Batterieladestrom"] = ($DatenArray["SolarAL"]*10)+($DatenArray["SolarAR"]/10);
  $aktuelleDaten["Batterieentladestrom"] = ($DatenArray["LoadAL"]*10)+($DatenArray["LoadAR"]/10);
  $aktuelleDaten["WattstundenGesamt"] = ($DatenArray["kwhGesamtL"]*10)+($DatenArray["kwhGesamtR"]/10);
  $aktuelleDaten["AmperestundenGesamt"] = ($DatenArray["ahGesamtL"]*10)+($DatenArray["ahGesamtR"]/10);
  $aktuelleDaten["Temperatur"] = $DatenArray["TempExt"];
  $aktuelleDaten["TemperaturVorzeichen"] = $DatenArray["TempVorzeichen"];
  if (date("H:i") == "00:00" or date("H:i") == "00:01") {
    $aktuelleDaten["WattstundenGesamtHeute"] = 0;
  }
  $aktuelleDaten["WattstundenGesamtHeute"] = round(($aktuelleDaten["WattstundenGesamt"] - $aktuelleDaten["WattstundenGesamtGestern"]),0);

  $aktuelleDaten["Solarleistung"] = round(($aktuelleDaten["Solarspannung"] * $aktuelleDaten["Batterieladestrom"]),0);

  /****************************************************************************
  //  ENDE REGLER AUSLESEN      ENDE REGLER AUSLESEN      ENDE REGLER AUSLESEN
  ****************************************************************************/


  /**************************************************************************
  //  Falls ein ErrorCode vorliegt, wird er hier in einen lesbaren
  //  Text umgewandelt, sodass er als Fehlermeldung gesendet werden kann.
  //  Die Funktion ist noch nicht überall implementiert.
  **************************************************************************/
  $FehlermeldungText = "";

  /****************************************************************************
  //  Die Daten werden für die Speicherung vorbereitet.
  ****************************************************************************/
  $aktuelleDaten["Regler"] = $Regler;
  $aktuelleDaten["Objekt"] = $Objekt;


  /**************************************************************************
  //  Alle ausgelesenen Daten werden hier bei Bedarf als mqtt Messages
  //  an den mqtt-Broker Mosquitto gesendet.
  //  Achtung! Die Übertragung dauert ca. 30 Sekunden!
  **************************************************************************/
  if ($MQTT and $i == 1) {
    $funktionen->log_schreiben("MQTT Daten zum [ $MQTTBroker ] senden.","   ",1);
    require($Pfad."/mqtt_senden.php");
  }


  /****************************************************************************
  //  Zeit und Datum
  ****************************************************************************/
  //  Der Regler hat keine interne Uhr! Deshalb werden die Daten vom Raspberry benutzt.
  $aktuelleDaten["Timestamp"] = time();
  $aktuelleDaten["Monat"]     = date("n");
  $aktuelleDaten["Woche"]     = date("W");
  $aktuelleDaten["Wochentag"] = strftime("%A",time());
  $aktuelleDaten["Datum"]     = date("d.m.Y");
  $aktuelleDaten["Uhrzeit"]      = date("H:i:s");


  /****************************************************************************
  //  InfluxDB  Zugangsdaten ...stehen in der user.config.php
  //  falls nicht, sind das hier die default Werte.
  ****************************************************************************/
  $aktuelleDaten["InfluxAdresse"] = $InfluxAdresse;
  $aktuelleDaten["InfluxPort"] = $InfluxPort;
  $aktuelleDaten["InfluxUser"] =  $InfluxUser;
  $aktuelleDaten["InfluxPassword"] = $InfluxPassword;
  $aktuelleDaten["InfluxDBName"] = $InfluxDBName;
  $aktuelleDaten["InfluxDaylight"] = $InfluxDaylight;
  $aktuelleDaten["InfluxDBLokal"] = $InfluxDBLokal;
  $aktuelleDaten["InfluxSSL"] = $InfluxSSL;
  $aktuelleDaten["Demodaten"] = false;


  /*********************************************************************
  //  Daten werden in die Influx Datenbank gespeichert.
  //  Lokal und Remote bei Bedarf.
  *********************************************************************/
  if ($InfluxDB_remote) {
    // Test ob die Remote Verbindung zur Verfügung steht.
    if ($RemoteDaten) {
      $rc = $funktionen->influx_remote_test();
      if ($rc) {
        $rc = $funktionen->influx_remote($aktuelleDaten);
        if ($rc) {
          $RemoteDaten = false;
        }
      }
      else {
        $RemoteDaten = false;
      }
    }
    if ($InfluxDB_local) {
      $rc = $funktionen->influx_local($aktuelleDaten);
    }
  }
  else {
    $rc = $funktionen->influx_local($aktuelleDaten);
  }



  if (is_file($Pfad."/1.user.config.php")) {
    // Ausgang Multi-Regler-Version
    $Zeitspanne = (7 - (time() - $Start));
    $funktionen->log_schreiben("Multi-Regler-Ausgang. ".$Zeitspanne,"   ",2);
    if ($Zeitspanne > 0) {
      sleep($Zeitspanne);
    }
    break;
  }
  else {
    $funktionen->log_schreiben("Schleife: ".($i)." Zeitspanne: ".(floor((56 - (time() - $Start))/($Wiederholungen-$i+1))),"   ",9);
    sleep(floor((56 - (time() - $Start))/($Wiederholungen-$i+1)));
  }
  if ($Wiederholungen <= $i or $i >= 6) {
    $funktionen->log_schreiben("Schleife ".$i." Ausgang...","   ",8);
    break;
  }


  $i++;
} while (($Start + 54) > time());


if (isset($aktuelleDaten["Firmware"]) and isset($aktuelleDaten["Regler"])) {


  /*********************************************************************
  //  Jede Minute werden bei Bedarf einige Werte zur Homematic Zentrale
  //  übertragen.
  *********************************************************************/
  if (isset($Homematic) and $Homematic == true) {
    $funktionen->log_schreiben("Daten werden zur HomeMatic übertragen...","   ",8);
    require($Pfad."/homematic.php");
  }

  /*********************************************************************
  //  Sollen Nachrichten an einen Messenger gesendet werden?
  //  Bei einer Multi-Regler-Version sollte diese Funktion nur bei einem
  //  Gerät aktiviert sein.
  *********************************************************************/
  if (isset($Messenger) and $Messenger == true) {
    $funktionen->log_schreiben("Nachrichten versenden...","   ",8);
    require($Pfad."/meldungen_senden.php");
  }

  $funktionen->log_schreiben("OK. Datenübertragung erfolgreich.","   ",7);

}
else {
  $funktionen->log_schreiben("Keine gültigen Daten empfangen.","!! ",6);
}


/*****************************************************************************
//  Die Status Datei wird dazu benutzt, um die Leistung des Reglers
//  pro Tag zu speichern.
//  Jede Nacht 0 Uhr Tageszähler setzen
*****************************************************************************/
if (date("H:i") == "00:00" or date("H:i") == "00:01") {
  $rc = file_put_contents($StatusFile, $aktuelleDaten["WattstundenGesamt"]);
  $funktionen->log_schreiben("WattstundenGesamtGestern  gesetzt.","o--",5);
}

Ausgang:

$funktionen->log_schreiben("-------------   Stop   ivt_solarregler.php   ----------------- ","|--",6);

return;






?>