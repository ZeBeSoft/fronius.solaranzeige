#!/usr/bin/php
<?php

/*****************************************************************************
//  Solaranzeige Projekt             Copyright (C) [2016-2020]  [Ulrich Kunz]
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
//  Es dient dem Auslesen des openWB Wallbox über das LAN mittels MQTT
//  
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

$Tracelevel = 7;  //  1 bis 10  10 = Debug
$RemoteDaten = true;
$aktuelleDaten = array();
$Version = "";
$Start = time();  // Timestamp festhalten
$funktionen->log_schreiben("---------------   Start  openWB.php   ------------------------- ","|--",6);
setlocale(LC_TIME,"de_DE.utf8");


$MQTTBroker = $WR_IP;
$MQTTPort = $WR_Port;
$MQTTKeepAlive = 60;
$Ladepunkt = $WR_Adresse;


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


$MQTTDaten = array();


$client = new Mosquitto\Client();
$client->onConnect([$funktionen,'mqtt_connect']);
$client->onDisconnect([$funktionen, 'mqtt_disconnect']);
$client->onPublish([$funktionen,'mqtt_publish']);
$client->onSubscribe([$funktionen,'mqtt_subscribe']);
$client->onMessage([$funktionen,'mqtt_message']);

if (!empty($MQTTBenutzer) and !empty($MQTTKennwort)) {
  $client->setCredentials($MQTTBenutzer, $MQTTKennwort);
}
if ($MQTTSSL) {
  $client->setTlsCertificates($Pfad."/ca.cert");
  $client->setTlsInsecure(SSL_VERIFY_NONE);
}

$rc = $client->connect($MQTTBroker, $MQTTPort, $MQTTKeepAlive);
for ($i=1;$i<200;$i++) {
  // Warten bis der connect erfolgt ist.
  if (empty($MQTTDaten)) {
    $client->loop(100);
  }
  else {
    break;
  }
}

if ($MQTTDaten["MQTTConnectReturnCode"] <> 0) {
  $funktionen->log_schreiben("Kein Connect zum Broker möglich","   ",3);
  goto Ausgang;
}
$funktionen->log_schreiben("Connect zum Broker (openWB) erfolgreich.","   ",8);


$Topic = "openWB/config/#";



$client->subscribe("openWB/lp/$Ladepunkt/#", 0); // Subscribe
$client->loop(100);


$client->subscribe("openWB/global/+", 0); // Subscribe
$client->loop(100);

$client->subscribe("openWB/+", 0); // Subscribe
$client->loop(100);

$client->subscribe("openWB/system/Version", 0); // Subscribe
$client->loop(100);


/***************************************************************************
//  Einen Befehl an die Wallbox senden
//
//  Per MQTT  start = 1    amp = 6
//  Per HTTP  start_1      amp_6
//
***************************************************************************/
if (file_exists($Pfad."/../pipe/".$GeraeteNummer.".befehl.steuerung")) {

    $funktionen->log_schreiben("Steuerdatei '".$GeraeteNummer.".befehl.steuerung' vorhanden----","|- ",5);
    $Inhalt = file_get_contents($Pfad."/../pipe/".$GeraeteNummer.".befehl.steuerung");
    $Befehle = explode("\n",trim($Inhalt));
    $funktionen->log_schreiben("Befehle: ".print_r($Befehle,1),"|- ",9);

    for ($i = 0; $i < count($Befehle); $i++) {

      if ($i >= 6) {
        //  Es werden nur maximal 5 Befehle pro Datei verarbeitet!
        break;
      }
      /*********************************************************************************
      //  In der Datei "befehle.ini.php" müssen alle gültigen Befehle aufgelistet
      //  werden, die man benutzen möchte.
      //  Achtung! Genau darauf achten, dass der Befehl richtig geschrieben wird,
      //  damit das Gerät keinen Schaden nimmt.
      //  curr_6000 ist nur zum Testen ...
      //  Siehe Dokument:  Befehle_senden.pdf
      *********************************************************************************/
      if (file_exists($Pfad."/befehle.ini.php")) {

        $funktionen->log_schreiben("Die Befehlsliste 'befehle.ini.php' ist vorhanden----","|- ",9);
        $INI_File =  parse_ini_file($Pfad.'/befehle.ini.php', true);
        $Regler35 = $INI_File["Regler35"];
        $funktionen->log_schreiben("Befehlsliste: ".print_r($Regler35,1),"|- ",9);

        foreach ($Regler35 as $Template) {
          $Subst = $Befehle[$i];
          $l = strlen($Template);
          for ($p = 1; $p < $l; ++$p) {
            $funktionen->log_schreiben("Template: ".$Template." Subst: ".$Subst." l: ".$l,"|- ",10);
            if ($Template[$p] == "#") {
              $Subst[$p] = "#";
            }
          }
          if ($Template == $Subst) {
            break;
          }
        }
        if ($Template != $Subst) {
          $funktionen->log_schreiben("Dieser Befehl ist nicht zugelassen. ".$Befehle[$i],"|o ",3);
          $funktionen->log_schreiben("Die Verarbeitung der Befehle wird abgebrochen.","|o ",3);
          break;
        }
      }
      else {
        $funktionen->log_schreiben("Die Befehlsliste 'befehle.ini.php' ist nicht vorhanden----","|- ",3);
        break;
      }

      $Teile = explode("_",$Befehle[$i]);
      $Antwort = "";
      // Hier wird der Befehl gesendet...
      //  $Teile[0] = Befehl
      //  $Teile[1] = Wert

      if (strtolower($Teile[0]) == "start") {
        if ($Teile[1] == 0) {
          $sendenachricht = hex2bin("000100000006FF0501900000");  //  Ladung unterbrechen
        }
        else {
          $sendenachricht = hex2bin("000100000006FF050190FF00");  //  Ladung einschalten
        }
      }
      if (strtolower($Teile[0]) == "amp") {
        $Ampere = floor($Teile[1]/100);
        $AmpHex = str_pad(dechex($Ampere),4,"0",STR_PAD_LEFT);
        $sendenachricht = hex2bin("000100000006FF060210".$AmpHex);  //  30 = 1E = 3 Ampere

      }
      $rc = fwrite($COM1, $sendenachricht);
      $Antwort = bin2hex(fread($COM1,1000));      // 1000 Bytes lesen
      $funktionen->log_schreiben("Antwort: ".$Antwort,"   ",3);

      sleep(2);
    }
    $rc = unlink($Pfad."/../pipe/".$GeraeteNummer.".befehl.steuerung");
    if ($rc) {
      $funktionen->log_schreiben("Datei  /../pipe/".$GeraeteNummer.".befehl.steuerung  gelöscht.","    ",9);
    }
}
else {
  $funktionen->log_schreiben("Steuerdatei '".$GeraeteNummer.".befehl.steuerung' nicht vorhanden----","|- ",9);
}




$i = 1;
do {

  /***************************************************************************
  //  Ab hier wird die Wallbox ausgelesen.
  //
  ***************************************************************************/


  for ($k=1; $k < 65; $k++) {

    $client->loop(100);

    if (isset($MQTTDaten["MQTTMessageReturnText"]) and $MQTTDaten["MQTTMessageReturnText"] == "RX-OK") {

      $funktionen->log_schreiben(print_r($MQTTDaten["MQTTTopic"],1),"*- ",10);

      /*************************************************************************
      //  MQTT Meldungen empfangen. Subscribing    Subscribing    Subscribing
      //  Hier werden die Daten vom Mosquitto Broker gelesen.
      *************************************************************************/

      $TopicTeile = explode("/",$MQTTDaten["MQTTTopic"]);
      if ($TopicTeile[1] == "lp") {
        $aktuelleDaten[$TopicTeile[3]] = $MQTTDaten["MQTTNachricht"];
      }
      elseif ($TopicTeile[1] == "global") {
        $aktuelleDaten[$TopicTeile[2]] = $MQTTDaten["MQTTNachricht"];
      }
      elseif ($TopicTeile[1] == "system") {
        $aktuelleDaten[$TopicTeile[2]] = $MQTTDaten["MQTTNachricht"];
      }
      else {
        $aktuelleDaten[$TopicTeile[1]] = $MQTTDaten["MQTTNachricht"];
      }
      if (count($aktuelleDaten) > 55) {
        break;
      }
    }
  }


  /**************************************************************************
  //  Ende Wallbox auslesen
  ***************************************************************************/

  $FehlermeldungText = "";


  /****************************************************************************
  //  Die Daten werden für die Speicherung vorbereitet.
  ****************************************************************************/
  $aktuelleDaten["Regler"] = $Regler;
  $aktuelleDaten["Objekt"] = $Objekt;
  $aktuelleDaten["Produkt"] = "openWB";
  $aktuelleDaten["Firmware"] = $aktuelleDaten["Version"]; 

  $aktuelleDaten["WattstundenGesamtHeute"] =  $aktuelleDaten["kWhDailyCharged"];

  //  Stationsstatus 0 = Aus
  //  Stationsstatus 1 = Ein + Angeschlossenem Kabel
  //  Stationsstatus 2 = Ein + Ladung + Angeschlossenem Kabel
  //  Stationsstatus 3 = Ein - Ladung + Angeschlossenem Kabel
  //  Stationsstatus 4 = Keine Ladung + Angeschlossenem Kabel
  //  Stationsstatus 5 = Standby + Angeschlossenem Kabel

  if ($aktuelleDaten["boolPlugStat"] == 0 and $aktuelleDaten["ChargePointEnabled"] == 0) {
    $aktuelleDaten["Stationsstatus"] = 0;
  }
  elseif ($aktuelleDaten["boolPlugStat"] == 0 and $aktuelleDaten["ChargePointEnabled"] == 1) {
    $aktuelleDaten["Stationsstatus"] = 1;
  }
  elseif ($aktuelleDaten["boolChargeStat"] == 1 and $aktuelleDaten["boolPlugStat"] == 1) {
    $aktuelleDaten["Stationsstatus"] = 2;
  }
  elseif ($aktuelleDaten["boolChargeStat"] == 0 and $aktuelleDaten["boolPlugStat"] == 1) {
    $aktuelleDaten["Stationsstatus"] = 3;
  }
  elseif ($aktuelleDaten["ChargeMode"] == 3 and $aktuelleDaten["boolPlugStat"] == 1) {
    $aktuelleDaten["Stationsstatus"] = 4;
  }
  elseif ($aktuelleDaten["ChargeMode"] == 4 and $aktuelleDaten["boolPlugStat"] == 1) {
    $aktuelleDaten["Stationsstatus"] = 5;
  }
  else {
    $aktuelleDaten["Stationsstatus"] = 0;
    $funktionen->log_schreiben(print_r($aktuelleDaten,1),"***",7);

  }

  /*************************************************************************************
  //  Ladestatus der Wallbox-Steuerung
  //  Ladestatus 1 = Nicht bereit
  //  Ladestatus 2 = Bereit zum Laden
  //  Ladestatus 3 = Es wird geladen
  //  Ladestatus 4 = Ladung beendet
  //  Ladestatus 5 = Ladung unterbrochen
  *************************************************************************************/

  //  Ladestatus = 1  Ladestation bereit, Kabel nicht verriegelt  LED  weiß
  //  Ladestatus = 2  Ladestatus bereit, Kabel verriegelt         LED  grün
  //  Ladestatus = 3  Kabel verriegelt, Auto wird geladen         LED  blau

  if ($aktuelleDaten["boolPlugStat"] == 0 and $aktuelleDaten["ChargePointEnabled"] == 1) {
    $aktuelleDaten["Ladestatus"] = 1;
  }
  elseif ($aktuelleDaten["boolPlugStat"] == 1 and $aktuelleDaten["ChargePointEnabled"] == 1) {
    $aktuelleDaten["Ladestatus"] = 2;
  }
  elseif ($aktuelleDaten["ChargeMode"] == 3 and $aktuelleDaten["boolPlugStat"] == 1) {
    $aktuelleDaten["Ladestatus"] = 3;
  }
  else {
    $aktuelleDaten["Ladestatus"] = 0;
  }

  if (!isset($aktuelleDaten["ADirectModeAmps"])) {
    $aktuelleDaten["ADirectModeAmps"] = 0;
  }


  $funktionen->log_schreiben(print_r($aktuelleDaten,1),"*- ",8);


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
  $aktuelleDaten["Uhrzeit"]   = date("H:i:s");


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
    $funktionen->log_schreiben("Schleife: ".($i)." Zeitspanne: ".(floor(((9*$i) - (time() - $Start))/($Wiederholungen-$i+1))),"   ",9);
    sleep(floor(((9*$i) - (time() - $Start))/($Wiederholungen-$i+1)));
  }
  if ($Wiederholungen <= $i or $i >= 6) {
    $funktionen->log_schreiben("Schleife ".$i." Ausgang...","   ",5);
    break;
  }

  $i++;
} while (($Start + 54) > time());


if (1 == 1) {


  /*********************************************************************
  //  Jede Minute werden bei Bedarf einige Werte zur Homematic Zentrale
  //  übertragen.
  *********************************************************************/
  if (isset($Homematic) and $Homematic == true) {
    $aktuelleDaten["Solarspannung"] = $aktuelleDaten["Solarspannung1"];
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

$funktionen->log_schreiben("---------------   Stop   openWB.php   ------------------------- ","|--",6);

return;






?>
