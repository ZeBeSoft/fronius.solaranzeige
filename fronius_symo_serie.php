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
//  Es dient dem Auslesen des Fronius Symo Wechselrichters über die LAN Schnittstelle
//  Port = 80
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
$DatenOK = true;
$Device = "WR"; // WR = Wechselrichter
$aktuelleDaten = array();
$Version = "";
$Start = time();  // Timestamp festhalten
$funktionen->log_schreiben("-------------   Start  fronius_symo_serie.php    --------------- ","|--",6);
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
$funktionen->log_schreiben("Hardware Version: ".$Version,"o  ",9);

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

if($funktionen->tageslicht() or $InfluxDaylight === false)  {
  //  Der Wechselrichter wird nur am Tage abgefragt.
  //  Er hat einen direkten LAN Anschluß

  $COM1 = fsockopen($WR_IP, $WR_Port, $errno, $errstr, 5);
  if (!is_resource($COM1)) {
    $funktionen->log_schreiben("Kein Kontakt zum Wechselrichter ".$WR_IP."  Port: ".$WR_Port,"XX ",3);
    $funktionen->log_schreiben("Exit.... ","XX ",3);
    goto Ausgang;
  }
}
else {
  $funktionen->log_schreiben("Es ist dunkel... ","X  ",7);
  goto Ausgang;
}

$i = 1;
do {
  $funktionen->log_schreiben("Die Daten werden ausgelesen...","+  ",9);

  /****************************************************************************
  //  Ab hier wird der Wechselrichter ausgelesen.
  //
  //  Ergebniswerte:
  //  $aktuelleDaten["Firmware"]                Nummer
  //  $aktuelleDaten["Produkt"]                 Text
  //  $aktuelleDaten["Objekt"]                  Text
  //  $aktuelleDaten["Batteriestrom"]
  //  $aktuelleDaten["Batteriespannung"]
  //  $aktuelleDaten["AC_Ausgangsstrom"]
  //  $aktuelleDaten["AC_Ausgangsstrom_R"]
  //  $aktuelleDaten["AC_Ausgangsstrom_S"]
  //  $aktuelleDaten["AC_Ausgangsstrom_T"]
  //  $aktuelleDaten["AC_Ausgangsspannung"]
  //  $aktuelleDaten["AC_Ausgangsspannung_R"]
  //  $aktuelleDaten["AC_Ausgangsspannung_S"]
  //  $aktuelleDaten["AC_Ausgangsspannung_T"]
  //  $aktuelleDaten["AC_Wirkleistung"]
  //  $aktuelleDaten["AC_Wirkleistung_R"]
  //  $aktuelleDaten["AC_Wirkleistung_S"]
  //  $aktuelleDaten["AC_Wirkleistung_T"]
  //  $aktuelleDaten["AC_Ausgangsfrequenz"]
  //  $aktuelleDaten["WattstundenGesamt"]
  //  $aktuelleDaten["WattstundenGesamtHeute"]
  //  $aktuelleDaten["WattstundenGesamtJahr"]
  //  $aktuelleDaten["ErrorCodes"]
  //
  //  EnergyReal_WAC_Minus_Absolute = Einspeisung
  //  EnergyReal_WAC_Plus_Absolute = Netzbezug
  //  E_Total = Solarproduktion
  //  Solarproduktion - Einspeisung = Direktverbrauch
  //  Netzbezug + Direktverbrauch = Hausverbrauch
  //
  //
  ****************************************************************************/



  $rc = $funktionen->read($WR_IP,$WR_Port,"solar_api/GetAPIVersion.cgi");
  // API Version prüfen. Es muss API Version 1 ein.
  if ($rc["APIVersion"] != 1) {
    $funktionen->log_schreiben("Falsche API Version.".print_r($rc,1),3);
    $funktionen->log_schreiben("Exit.... ","XX ",3);
    exit;
  }
  $aktuelleDaten["Firmware"] = $rc["APIVersion"];


  $URL  = "solar_api/v1/GetInverterRealtimeData.cgi";
  $URL .= "?Scope=Device";
  $URL .= "&DeviceID=".$WR_Adresse;
  $URL .= "&DataCollection=CumulationInverterData";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      $aktuelleDaten["WattstundenGesamtHeute"] = $JSON_Daten["Body"]["Data"]["DAY_ENERGY"]["Value"];
      $aktuelleDaten["WattstundenGesamtJahr"] = $JSON_Daten["Body"]["Data"]["YEAR_ENERGY"]["Value"];
      $aktuelleDaten["WattstundenGesamt"] = $JSON_Daten["Body"]["Data"]["TOTAL_ENERGY"]["Value"];
      $aktuelleDaten["Geraetestatus"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["StatusCode"];
      $aktuelleDaten["ErrorCodes"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["ErrorCode"];
  }
  else {
    break;
  }






  $URL  = "solar_api/v1/GetInverterRealtimeData.cgi";
  $URL .= "?Scope=Device";
  $URL .= "&DeviceID=".$WR_Adresse;
  $URL .= "&DataCollection=CommonInverterData";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      $aktuelleDaten["AC_Wirkleistung"] = $JSON_Daten["Body"]["Data"]["PAC"]["Value"];
      $aktuelleDaten["AC_Ausgangsstrom"] = $JSON_Daten["Body"]["Data"]["IAC"]["Value"];
      $aktuelleDaten["AC_Ausgangsspannung"] = $JSON_Daten["Body"]["Data"]["UAC"]["Value"];
      $aktuelleDaten["AC_Ausgangsfrequenz"] = $JSON_Daten["Body"]["Data"]["FAC"]["Value"];
      $aktuelleDaten["Solarstrom"] = $JSON_Daten["Body"]["Data"]["IDC"]["Value"];
      $aktuelleDaten["Solarspannung"] = $JSON_Daten["Body"]["Data"]["UDC"]["Value"];
      $aktuelleDaten["Geraetestatus"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["StatusCode"];
      $aktuelleDaten["ErrorCodes"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["ErrorCode"];
  }
  else {
    break;
  }


  $URL  = "solar_api/v1/GetInverterInfo.cgi";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

       $aktuelleDaten["ModulPVLeistung"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["PVPower"];
  }
  else {
    break;
  }



  $URL  = "solar_api/v1/GetArchiveData.cgi";
  $URL .= "?Scope=System";
  $URL .= "&StartDate=".date(DATE_RFC3339,time()-400);
  $URL .= "&EndDate=".date(DATE_RFC3339);
  $URL .= "&Channel=Voltage_DC_String_1";
  $URL .= "&Channel=Voltage_DC_String_2";
  $URL .= "&Channel=Current_DC_String_1";
  $URL .= "&Channel=Current_DC_String_2";
  $URL .= "&Channel=Temperature_Powerstage";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      $aktuelleDaten["Solarspannung_String_1"] = end($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Voltage_DC_String_1"]["Values"]);
      $aktuelleDaten["Solarstrom_String_1"] = end($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Current_DC_String_1"]["Values"]);
      if (isset($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Voltage_DC_String_2"])) {
        $aktuelleDaten["Solarspannung_String_2"] = end($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Voltage_DC_String_2"]["Values"]);
        $aktuelleDaten["Solarstrom_String_2"] = end($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Current_DC_String_2"]["Values"]);
      }
      $aktuelleDaten["Temperatur"] = end($JSON_Daten["Body"]["Data"]["inverter/".$WR_Adresse]["Data"]["Temperature_Powerstage"]["Values"]);
  }
  else {
    break;
  }



  $URL  = "/solar_api/v1/GetActiveDeviceInfo.cgi";
  $URL .= "?DeviceClass=System";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

    $aktuelleDaten["Inverter"] = count($JSON_Daten["Body"]["Data"]["Inverter"]);
    $aktuelleDaten["Meter"] = count($JSON_Daten["Body"]["Data"]["Meter"]);
    $aktuelleDaten["StringControl"] = count($JSON_Daten["Body"]["Data"]["StringControl"]);
    $aktuelleDaten["Ohmpilot"] = count($JSON_Daten["Body"]["Data"]["Ohmpilot"]);
    $aktuelleDaten["SensorCard"] = count($JSON_Daten["Body"]["Data"]["SensorCard"]);
    $aktuelleDaten["Storage"] = count($JSON_Daten["Body"]["Data"]["Storage"]);
    $aktuelleDaten["InverterID"] = $JSON_Daten["Body"]["Data"]["Inverter"][$WR_Adresse]["DT"];
  }
  else {
    break;
  }



  $URL  = "/solar_api/v1/GetPowerFlowRealtimeData.fcgi";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);
    $aktuelleDaten["SummeWattstundenGesamtHeute"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Day"];
    $aktuelleDaten["SummeWattstundenGesamtJahr"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Year"];
    $aktuelleDaten["SummeWattstundenGesamt"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Total"];
    $aktuelleDaten["Meter_Location"] = $JSON_Daten["Body"]["Data"]["Site"]["Meter_Location"];
    $aktuelleDaten["Mode"] = $JSON_Daten["Body"]["Data"]["Site"]["Mode"];
    $aktuelleDaten["SummePowerGrid"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Grid"];
    $aktuelleDaten["SummePowerLoad"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Load"];
    $aktuelleDaten["SummePowerAkku"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Akku"];
    $aktuelleDaten["SummePowerPV"] = $JSON_Daten["Body"]["Data"]["Site"]["P_PV"];
    $aktuelleDaten["Rel_Autonomy"] = $JSON_Daten["Body"]["Data"]["Site"]["rel_Autonomy"];
    $aktuelleDaten["Rel_SelfConsumption"] = $JSON_Daten["Body"]["Data"]["Site"]["rel_SelfConsumption"];
    if (isset($JSON_Daten["Body"]["Data"]["Inverters"]["1"]["SOC"])) {
      $aktuelleDaten["Akkustand_SOC"]  = $JSON_Daten["Body"]["Data"]["Inverters"]["1"]["SOC"];
    }
    else {
      $aktuelleDaten["Akkustand_SOC"]  = 0;
    }
  }
  else {
    break;
  }



  if ($aktuelleDaten["Meter"] == 1)  {

    $URL  = "/solar_api/v1/GetMeterRealtimeData.cgi";
    $URL .= "?Scope=System";
    $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
    if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
      // Es handelt sich um gültige Daten
      $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      if (isset($JSON_Daten["Body"]["Data"]["0"])) {  // Channel 0
        $aktuelleDaten["Meter_Wirkleistung"] = $JSON_Daten["Body"]["Data"]["0"]["PowerReal_P_Sum"];
        if (isset($JSON_Daten["Body"]["Data"]["0"]["PowerReactive_Q_Sum"])) {
          $aktuelleDaten["Meter_Scheinleistung"] = $JSON_Daten["Body"]["Data"]["0"]["PowerReactive_Q_Sum"];
          $aktuelleDaten["Meter_Blindleistung"] = $JSON_Daten["Body"]["Data"]["0"]["PowerApparent_S_Sum"];
          $aktuelleDaten["Meter_EnergieProduziert"] = $JSON_Daten["Body"]["Data"]["0"]["EnergyReal_WAC_Sum_Produced"];
          $aktuelleDaten["Meter_EnergieVerbraucht"] = $JSON_Daten["Body"]["Data"]["0"]["EnergyReal_WAC_Sum_Consumed"];
        }
        else {
          $aktuelleDaten["Meter_Scheinleistung"] = 0;
          $aktuelleDaten["Meter_Blindleistung"] = 0;
          $aktuelleDaten["Meter_EnergieProduziert"] = 0;
          $aktuelleDaten["Meter_EnergieVerbraucht"] = 0;
        }

      }
      elseif (isset($JSON_Daten["Body"]["Data"]["1"])) {  //  Channel 1
        $aktuelleDaten["Meter_Wirkleistung"] = $JSON_Daten["Body"]["Data"]["1"]["PowerReal_P_Sum"];
        if (isset($JSON_Daten["Body"]["Data"]["1"]["PowerReactive_Q_Sum"])) {
          $aktuelleDaten["Meter_Scheinleistung"] = $JSON_Daten["Body"]["Data"]["1"]["PowerReactive_Q_Sum"];
          $aktuelleDaten["Meter_Blindleistung"] = $JSON_Daten["Body"]["Data"]["1"]["PowerApparent_S_Sum"];
          $aktuelleDaten["Meter_EnergieProduziert"] = $JSON_Daten["Body"]["Data"]["1"]["EnergyReal_WAC_Sum_Produced"];
          $aktuelleDaten["Meter_EnergieVerbraucht"] = $JSON_Daten["Body"]["Data"]["1"]["EnergyReal_WAC_Sum_Consumed"];
        }
        else {
          $aktuelleDaten["Meter_Scheinleistung"] = 0;
          $aktuelleDaten["Meter_Blindleistung"] = 0;
          $aktuelleDaten["Meter_EnergieProduziert"] = 0;
          $aktuelleDaten["Meter_EnergieVerbraucht"] = 0;
        }
      }

    }
    else {
      break;
    }

  }



  if ($aktuelleDaten["Ohmpilot"] == 1)  {

    $URL  = "/solar_api/v1/GetOhmPilotRealtimeData.cgi";
    $URL .= "?Scope=System";
    $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);

    if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
      // Es handelt sich um gültige Daten
      $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      if (isset($JSON_Daten["Body"]["Data"]["0"])) {  // Channel 0
        $aktuelleDaten["Ohmpilot_EnergieGesamt"] = $JSON_Daten["Body"]["Data"]["0"]["EnergyReal_WAC_Sum_Consumed"];
        $aktuelleDaten["Ohmpilot_Wirkleistung"] = $JSON_Daten["Body"]["Data"]["0"]["PowerReal_PAC_Sum"];
      }
    }
    else {
      break;
    }

  }



  $URL  = "/solar_api/v1/GetStringRealtimeData.cgi";
  $URL .= "?Scope=System";
  $URL .= "&DataCollection=NowStringControlData";
  $funktionen->log_schreiben(print_r($funktionen->read($WR_IP,$WR_Port,$URL),1),"   ",10);



  foreach($aktuelleDaten as $key=>$wert) {
    if (empty($wert))  {
      $aktuelleDaten[$key] = 0;
    }
  }


  if ($aktuelleDaten["Storage"] == 1)  {

    $URL  = "/solar_api/v1/GetStorageRealtimeData.cgi";
    $URL .= "?Scope=System";

    $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);

    if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
      // Es handelt sich um gültige Daten
      $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      if (isset($JSON_Daten["Body"]["Data"]["0"])) {  // Channel 0
        $aktuelleDaten["Batterie_Max_Kapazitaet"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Capacity_Maximum"];
        $aktuelleDaten["Batterie_Strom_DC"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Current_DC"];
        $aktuelleDaten["Batterie_Hersteller"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Details"]["Manufacturer"];
        $aktuelleDaten["Batterie_Seriennummer"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Details"]["Serial"];
        $aktuelleDaten["Batterie_StateOfCharge_Relative"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["StateOfCharge_Relative"];
        $aktuelleDaten["Batterie_Status_Batteriezellen"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Status_BatteryCell"];
        $aktuelleDaten["Batterie_Zellentemperatur"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Temperature_Cell"];
        $aktuelleDaten["Batterie_Spannung_DC"] = $JSON_Daten["Body"]["Data"]["0"]["Controller"]["Voltage_DC"];
      }
    }
    else {
      break;
    }
  }



  /****************************************************************************
  //  ENDE REGLER AUSLESEN      ENDE REGLER AUSLESEN      ENDE REGLER AUSLESEN
  ****************************************************************************/



  /**************************************************************************
  //  Falls ein ErrorCode vorliegt, wird er hier in einen lesbaren
  //  Text umgewandelt, sodass er als Fehlermeldung gesendet werden kann.
  //  Die Funktion ist noch nicht überall implementiert.
  **************************************************************************/
  $FehlermeldungText = $funktionen->fronius_getFehlerString($aktuelleDaten["ErrorCodes"]);

  /****************************************************************************
  //  Wird für die HomeMatic Anbindung benötigt
  ****************************************************************************/

  $aktuelleDaten["Solarleistung"] = ($aktuelleDaten["Solarspannung"] * $aktuelleDaten["Solarstrom"]);
  $aktuelleDaten["AC_Leistung"] = $aktuelleDaten["AC_Wirkleistung"];

  /***************************************************************************/

  if (isset($aktuelleDaten["SummePowerGrid"])) {
    if ($aktuelleDaten["SummePowerGrid"] < 0 ) {
      $aktuelleDaten["Einspeisung"] = abs($aktuelleDaten["SummePowerGrid"]);
      $aktuelleDaten["Verbrauch"] = abs($aktuelleDaten["SummePowerLoad"]);
      $aktuelleDaten["Bezug"] = 0;
    }
    else {
      $aktuelleDaten["Einspeisung"] = 0; 
      $aktuelleDaten["Bezug"] = $aktuelleDaten["SummePowerGrid"];
      $aktuelleDaten["Verbrauch"] = abs($aktuelleDaten["SummePowerLoad"]);
    }
  }



  /****************************************************************************
  //  Die Daten werden für die Speicherung vorbereitet.
  ****************************************************************************/
  $aktuelleDaten["Produkt"] = "Fronius Symo";
  $aktuelleDaten["Regler"] = $Regler;
  $aktuelleDaten["Objekt"] = $Objekt;


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
    $funktionen->log_schreiben("Schleife: ".($i)." Zeitspanne: ".(floor((54 - (time() - $Start))/($Wiederholungen-$i+1))),"   ",9);
    sleep(floor((54 - (time() - $Start))/($Wiederholungen-$i+1)));
  }
  if ($Wiederholungen <= $i or $i >= 6) {
    $funktionen->log_schreiben("OK. Daten gelesen.","   ",9);
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


$funktionen->log_schreiben("-------------   Stop   fronius_symo_serie.php    --------------- ","|--",6);

return;




?>
