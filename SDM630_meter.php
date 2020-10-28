#!/usr/bin/php
<?php
/*****************************************************************************
//  Solaranzeige Projekt             Copyright (C) [2015-2016]  [Ulrich Kunz]
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
//  Es dient dem Auslesen der Regler der Tracer Serie über die USB Schnittstelle
//  Das Auslesen wird hier mit einer Schleife durchgeführt. Wie oft die Daten
//  ausgelesen und gespeichert werden steht in der user.config.php
//
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

$Tracelevel = 7;  //  1 bis 10  10 = Debug
$Version = "";
$Device = "ME"; // ME = Smart Meter
$aktuelleDaten = array();
$RemoteDaten = true;


if (empty($WR_Adresse)) {
  $WR_ID = "01";
}
elseif(strlen($WR_Adresse) == 1)  {
  $WR_ID = str_pad($WR_Adresse,2,"0",STR_PAD_LEFT);
}
else {
  $WR_ID = str_pad(substr($WR_Adresse,-2),2,"0",STR_PAD_LEFT);
}

$funktionen->log_schreiben("WR_ID: ".$WR_ID,"+  ",7);


$Befehl = array(
  "DeviceID" => $WR_ID,
  "BefehlFunctionCode" => "04",
  "RegisterAddress" => "3001",
  "RegisterCount" => "0001" );



$Start = time();  // Timestamp festhalten
$funktionen->log_schreiben("---------   Start  SDM630_meter.php  ------------------------- ","|--",6);
setlocale(LC_TIME,"de_DE.utf8");


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



//  Nach em Öffnen des Port muss sofort der Regler ausgelesen werden, sonst
//  sendet er asynchrone Daten!
$USB1 = $funktionen->openUSB($USBRegler);
if (!is_resource($USB1)) {
  $funktionen->log_schreiben("USB Port kann nicht geöffnet werden. [1]","XX ",7);
  $funktionen->log_schreiben("Exit.... ","XX ",7);
  goto Ausgang;
}


$i = 1;
do {
  $funktionen->log_schreiben("Die Daten werden ausgelesen...",">  ",9);

  /**************************************************************************
  //  Ab hier wird der Energy Meter ausgelesen.
  //
  //  Ergebniswerte:
  // 
  // 
  // 
  // 
  // 
  // 
  // 
  //  $aktuelleDaten["Temperatur"]
  //
  **************************************************************************/



  /****************************************************************************
  //  Ab hier wird der Zähler ausgelesen.
  //
  ****************************************************************************/
  //  Firmware Version     Firmware Version     Firmware Version     Firmware
  //  Firmware Version     Firmware Version     Firmware Version     Firmware

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0000";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Spannung_R"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0002";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Spannung_S"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0004";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Spannung_T"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0006";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Strom_R"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0008";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Strom_S"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "000A";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Strom_T"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "000C";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Leistung_R"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "000E";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Leistung_S"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0010";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Leistung_T"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "001E";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["PF_R"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0020";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["PF_S"] = $rc;

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0022";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["PF_T"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "002A";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Spannung"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0030";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Strom"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0034";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["AC_Leistung"] = $rc;

  $funktionen->log_schreiben("AC Leistung: ".$aktuelleDaten["AC_Leistung"]." Watt","   ",6);

  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "003E";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["PF_Leistung"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0046";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["Frequenz"] = $rc;


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0048";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["Wh_Bezug"] = round($rc*1000);


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "004A";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["Wh_Einspeisung"] = round($rc*1000);


  $Befehl["DeviceID"] = $WR_ID;
  $Befehl["RegisterAddress"] = "0054";
  $Befehl["BefehlFunctionCode"] = "04";
  $Befehl["RegisterCount"] = "0002";
  $rc = $funktionen->sdm_auslesen($USB1,$Befehl);
  $aktuelleDaten["GesamterLeistungsbedarf"] = $rc;



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
  $aktuelleDaten["Firmware"] = 0;
  $aktuelleDaten["Produkt"]  = "SDM630";
  $aktuelleDaten["WattstundenGesamtHeute"]  = 0;  // dummy

  $funktionen->log_schreiben(var_export($aktuelleDaten,1),"   ",8);


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
  $aktuelleDaten["InfluxPort"] =  $InfluxPort;
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
    $Zeitspanne = (9 - (time() - $Start));
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
    $funktionen->log_schreiben("OK. Daten gelesen.","   ",9);
    $funktionen->log_schreiben("Schleife ".$i." Ausgang...","   ",8);
    break;
  }
  $i++;

} while (($Start + 56) > time());


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


Ausgang:


$funktionen->log_schreiben("---------   Stop   SDM630_meter.php    ----------------------- ","|--",6);

return;


?>
