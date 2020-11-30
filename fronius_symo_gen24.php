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
//  Es dient dem Auslesen des Fronius Symo Gen24 Wechselrichters über die LAN Schnittstelle
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
$RemoteDaten = false;
$DatenOK = true;
$Device = "WR"; // WR = Wechselrichter
$aktuelleDaten = array();
$syncData = array();
$energyData = array();
$Version = "";

// disable some queries
$GetInverterInfo = false;
$CumulationInverterData = false;
$GetMeterRealtimeData = true;

$MaxWiederholungen = 30;
$MaxScriptRuntime = 58.0;

$Start = time();  // Timestamp festhalten
$MicroStart = microtime(true);
$funktionen->log_schreiben("-------------   Start  fronius_symo_gen24.php    --------------- ","|--",6);
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

/////////////////////
function ini_read($fp)	
{	
    if (!is_resource($fp)) return false;
    if (false === ($_fstat = @fstat($fp))) return false; 	
    if (false === ($cont = fread($fp, $_fstat['size']))) return false;
    if (false === ($_data = @parse_ini_string($cont, true))) return false;

    return $_data;
}

/////////////////////
function ini_write($fp, $_data, $filename, $maxdepth=3)
{

    #--private Funktion -------------------------------------------------------------------------
    $writeparams = function ($_values, $arraykey, $depth) use ($fp, $maxdepth, &$writeparams)
    {
	foreach ($_values as $key => $param)
	{
            if ($depth >= 1)
	    {
		$arraykeytxt = $arraykey . "[$key]";
	    }	
	    else
	    {   
		$arraykeytxt = $key;
	    }

	    if (is_array($param))
	    {
		$depth++;
		if ($depth < $maxdepth)
		{
	            if (false === $writeparams ($param, $arraykeytxt, $depth)) return false;
		}	
	    }
	    else
	    {
		if (false === @fwrite ($fp, "$arraykeytxt = '$param'" . PHP_EOL)) return false;	
	    }
	}

	return true;
    };
    #------------------------------------------------------------------------------------------

    if ( 0 !== @fseek($fp, 0, SEEK_SET)) return false;
    if (false === @fwrite ($fp, ';### ' . basename($filename) . ' ### ' . 
        date('Y-m-d H:i:s') . ' ### utf-8 ### ÄÖÜäöü' . PHP_EOL . PHP_EOL)) return false;

    $depth = 0;
    $arraykey = '';
	
    foreach ($_data as $section => $_value)
    {
	if (is_array($_value))
	{
            if (false === @fwrite ($fp, PHP_EOL . "[$section]" . PHP_EOL)) return false;
			
            if ($depth < $maxdepth) 
            {
	    	$writeparams ($_value, $section, $depth); 
	    }
	}	
	else
        {
            if (false === @fwrite ($fp, "$section = '$_value'" . PHP_EOL)) return false;	
        }		
    }
	
    if (false === ($len = @ftell($fp))) return false;
    if (false === @ftruncate($fp, $len)) return false;
	
    return true;
}

/////////////////////
$energyDataType = array("GridFeed","GridPurchase","SelfConsumption","TotalConsumption","AC","PV");
$energyDataSpan = array("Day","Month","Year","Total","Power");

$energyDataTypeMeter = array("GridConsumed","GridProduced");
$energyDataSpanDayMonthYear = array("Day","Month","Year");
$energyDataSpanDayMonthYearTotal = array("Day","Month","Year","Total");
$energyDataSpanYearTotal = array("Year","Total");

/////////////////////
function defaultEnergyData() {
	global $energyDataType;
	global $energyDataSpan;
	
	$energyData = array();

	foreach($energyDataType as $type) {
		foreach($energyDataSpan as $span) {
			$energyData[$type][$span] = 0;
		}
	}
	
	$energyData["Timestamp"]["Day"] = date("z");
	$energyData["Timestamp"]["Month"] = date("n");
	$energyData["Timestamp"]["Year"] = date("Y");
	$energyData["Timestamp"]["epoche"] = time();
	
	return $energyData;
}

/////////////////////
function writeIniFile($iniFile,$iniData) {
	if (false === ($fp = @fopen($iniFile, 'wb'))) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht anlegen.","   ",5);
		return false;
	}
	elseif (!flock($fp, LOCK_EX)) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht sperren.","   ",5);
		fclose($fp);
		return false;
	}
	elseif (false === ini_write($fp, $iniData, $iniFile, 3)) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht schreiben.","   ",5);
		fclose($fp);
		return false;
	}
	fclose($fp);
	return true;
}

/////////////////////
function readIniFile($iniFile) {
	// $funktionen->log_schreiben(">>> readIniFile(".$iniFile.")","   ",5);
	$_iniData = array();
    if (false === ($fp = @fopen($iniFile, 'rb+'))) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht öffnen.","   ",5);
		return false;
	}
	elseif (!flock($fp, LOCK_EX)) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht sperren.","   ",5);
		fclose($fp);
		return false;
	}
	elseif (false === ($_iniData = ini_read($fp))) {
		// $funktionen->log_schreiben("Konnte die Datei ".$iniFile." nicht lesen.","   ",5);
		fclose($fp);
		return false;
	}
	// $_iniData["fileRead"] = 1;
	// $_iniData["fileOpen"] = 1;
	// $_iniData["fileLock"] = 1;
	fclose($fp);
	// $funktionen->log_schreiben("<<< readIniFile(".$iniFile.")","   ",5);
	return $_iniData;
}

/////////////////////
function calculateEnergyLinearInterpolated(&$energy, $power, $timeStamp, $lastPower, $lastTimeStamp) {
	$divisor = 3600 / ($timeStamp - $lastTimeStamp);
	$interpolatedPower = ($power + $lastPower) / 2;
	$energy = $energy + ($interpolatedPower / $divisor);
}

/////////////////////
function onTimestampChangedResetCounter(&$energyData, $span, $value) {
	global $energyDataType;
	if ($energyData["Timestamp"][$span] != $value) {
		if ($energyData["Timestamp"]["epoche"] != 0) {
			foreach($energyDataType as $type) {
				$energyData[$type][$span] = 0;
			}
		}
	}
}

/////////////////////
function onTimestampChangedSetEnergyCounterToActualValue(&$energyData, &$energyGrid, $span, $value) {
	global $energyDataTypeMeter;
	if ($energyData["Timestamp"][$span] != $value) {
		if ($energyData["Timestamp"]["epoche"] != 0) {
			foreach($energyDataTypeMeter as $type) {
				$energyData[$type][$span] = $energyGrid[$type];
			}
		}
	}
}

/////////////////////
function onTimestampChangedUpdate(&$energyData, $span, $value) {
	if ($energyData["Timestamp"][$span] != $value) {
        $energyData["Timestamp"][$span] = $value;
	}
}

/////////////////////


$syncDataFile = $Pfad."/database/".$GeraeteNummer.".syncData.ini";
if (file_exists($syncDataFile)) {
	$funktionen->log_schreiben("Datei ".$syncDataFile." vorhanden.","   ",5);
	if (false === ($syncData = readIniFile($syncDataFile))) {
		$funktionen->log_schreiben("Konnte die Datei ".$syncDataFile." nicht lesen.","   ",5);
	}
	else
	{
        $funktionen->log_schreiben(print_r($syncData,1),"*- ",8);
		if ((isset($syncData["connection"]["disabled"])) and $syncData["connection"]["disabled"] == 1)
		{
			$funktionen->log_schreiben("Verbindung zu Host '".$syncData["connection"]["host"]."' ist disabled.","   ",5);
		}
		else
		{
			$connection = ssh2_connect($syncData["connection"]["host"], 22);
			if ($connection)
			{
				ssh2_auth_password($connection, $syncData["connection"]["username"], $syncData["connection"]["password"]);
				ssh2_scp_recv($connection, $syncData["connection"]["source"], $syncData["connection"]["dest"]);		
				ssh2_disconnect($connection);
			}
			else
			{
				$funktionen->log_schreiben("Konnte keine Verbindung zu Host '".$syncData["connection"]["host"]."' aufbauen.","   ",5);
			}
		}
	}
}

$energyDataFile = $Pfad."/database/".$GeraeteNummer.".energyData.ini";
if (file_exists($energyDataFile)) {
	$funktionen->log_schreiben("Datei ".$energyDataFile." vorhanden.","   ",5);
	if (false === ($energyData = readIniFile($energyDataFile))) {
		$funktionen->log_schreiben("Konnte die Datei ".$energyDataFile." nicht lesen.","   ",5);
	}
}
else {
	$energyData = defaultEnergyData();
	if (true === writeIniFile($energyDataFile,$energyData)) {
		$funktionen->log_schreiben("Datei ".$energyDataFile." erzeugt.","   ",5);
	}
}
$aktuelleDaten["Energy"] = $energyData;

$funktionen->log_schreiben(print_r($energyData,1),"*- ",8);

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
  //
  //  Ab hier wird der Wechselrichter ausgelesen.
  //
  ****************************************************************************/

  $timeStampDevice = 0;

  $rc = $funktionen->read($WR_IP,$WR_Port,"solar_api/GetAPIVersion.cgi");
  // API Version prüfen. Es muss API Version 1 ein.
  if ($rc["APIVersion"] != 1) {
    $funktionen->log_schreiben("Falsche API Version.".print_r($rc,1),3);
    $funktionen->log_schreiben("Exit.... ","XX ",3);
    exit;
  }
  $aktuelleDaten["Firmware"] = $rc["APIVersion"];


  $URL  = "/solar_api/v1/GetActiveDeviceInfo.cgi";
  $URL .= "?DeviceClass=System";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

	$prefix = "DeviceInfo";
    if (isset($JSON_Daten["Body"]["Data"][$WR_Adresse])) {
      $aktuelleDaten[$prefix]["Inverter"] = 1;
      $aktuelleDaten[$prefix]["Inverter_Serial"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["Serial"];
      $aktuelleDaten[$prefix]["Inverter_ID"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["DT"];
	}
    $aktuelleDaten[$prefix]["Meter"] = count($JSON_Daten["Body"]["Data"]["Meter"]);
    $aktuelleDaten[$prefix]["Meter_Serial"] = $JSON_Daten["Body"]["Data"]["Meter"]["0"]["Serial"];
    $aktuelleDaten[$prefix]["Ohmpilot"] = count($JSON_Daten["Body"]["Data"]["Ohmpilot"]);
    $aktuelleDaten[$prefix]["Storage"] = count($JSON_Daten["Body"]["Data"]["Storage"]);
	
    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
  }
  else {
    break;
  }



if ($GetInverterInfo === true)
{
// GetInverterInfo
  $URL  = "solar_api/v1/GetInverterInfo.cgi";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

	$prefix = "InverterInfo";
    $aktuelleDaten[$prefix]["CustomName"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["CustomName"];
    $aktuelleDaten[$prefix]["DT"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["DT"];
    $aktuelleDaten[$prefix]["PVPower"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["PVPower"];
    $aktuelleDaten[$prefix]["Show"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["Show"];
    $aktuelleDaten[$prefix]["StatusCode"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["StatusCode"];
	$aktuelleDaten[$prefix]["UniqueID"] = $JSON_Daten["Body"]["Data"][$WR_Adresse]["UniqueID"];
	
    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
  }
  else {
    break;
  }
}

  // 3PInverterData
  $URL  = "solar_api/v1/GetInverterRealtimeData.cgi";
  $URL .= "?Scope=Device";
  $URL .= "&DeviceID=".$WR_Adresse;
  $URL .= "&DataCollection=3PInverterData";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

	$prefix = "Inverter3P";
    $aktuelleDaten[$prefix]["IAC_L1"] = $JSON_Daten["Body"]["Data"]["IAC_L1"]["Value"];
    $aktuelleDaten[$prefix]["IAC_L2"] = $JSON_Daten["Body"]["Data"]["IAC_L2"]["Value"];
    $aktuelleDaten[$prefix]["IAC_L3"] = $JSON_Daten["Body"]["Data"]["IAC_L3"]["Value"];
    $aktuelleDaten[$prefix]["T_AMBIENT"] = $JSON_Daten["Body"]["Data"]["T_AMBIENT"]["Value"];
    $aktuelleDaten[$prefix]["UAC_L1"] = isset($JSON_Daten["Body"]["Data"]["UAC_L1"]["Value"]) ? $JSON_Daten["Body"]["Data"]["UAC_L1"]["Value"] : 0;
    $aktuelleDaten[$prefix]["UAC_L2"] = isset($JSON_Daten["Body"]["Data"]["UAC_L2"]["Value"]) ? $JSON_Daten["Body"]["Data"]["UAC_L2"]["Value"] : 0;
    // $aktuelleDaten[$prefix]["UAC_L3"] = $JSON_Daten["Body"]["Data"]["UAC_L3"]["Value"];
	
    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
  }
  else {
    break;
  }


  if ($CumulationInverterData === true) {
  // CumulationInverterData
  $URL  = "solar_api/v1/GetInverterRealtimeData.cgi";
  $URL .= "?Scope=Device";
  $URL .= "&DeviceID=".$WR_Adresse;
  $URL .= "&DataCollection=CumulationInverterData";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

	$prefix = "InverterCumulation";
    $aktuelleDaten[$prefix]["InverterState"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["InverterState"]; // Sleeping oder Running
    $aktuelleDaten[$prefix]["PAC"] = $JSON_Daten["Body"]["Data"]["PAC"]["Value"];

    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
  }
  else {
    break;
  }
  }


  // CommonInverterData
  $URL  = "solar_api/v1/GetInverterRealtimeData.cgi";
  $URL .= "?Scope=Device";
  $URL .= "&DeviceID=".$WR_Adresse;
  $URL .= "&DataCollection=CommonInverterData";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

	$prefix = "InverterCommon";
    $aktuelleDaten[$prefix]["InverterState"] = $JSON_Daten["Body"]["Data"]["DeviceStatus"]["InverterState"]; // Sleeping oder Running
	$aktuelleDaten[$prefix]["FAC"] = isset($JSON_Daten["Body"]["Data"]["FAC"]["Value"]) ? $JSON_Daten["Body"]["Data"]["FAC"]["Value"] : 0;

	$aktuelleDaten[$prefix]["IAC"]["SUM"] = $JSON_Daten["Body"]["Data"]["IAC"]["Value"];
	$aktuelleDaten[$prefix]["IAC"]["L1"] = $JSON_Daten["Body"]["Data"]["IAC_L1"]["Value"];
	$aktuelleDaten[$prefix]["IAC"]["L2"] = $JSON_Daten["Body"]["Data"]["IAC_L2"]["Value"];
	$aktuelleDaten[$prefix]["IAC"]["L3"] = $JSON_Daten["Body"]["Data"]["IAC_L3"]["Value"];
	
	$aktuelleDaten[$prefix]["IDC"]["1"] = $JSON_Daten["Body"]["Data"]["IDC"]["Value"];
	$aktuelleDaten[$prefix]["IDC"]["2"] = $JSON_Daten["Body"]["Data"]["IDC_2"]["Value"];

	$aktuelleDaten[$prefix]["PAC"] = $JSON_Daten["Body"]["Data"]["PAC"]["Value"];
	$aktuelleDaten[$prefix]["SAC"] = $JSON_Daten["Body"]["Data"]["SAC"]["Value"];
	
	$aktuelleDaten[$prefix]["UAC"]["SUM"] = $JSON_Daten["Body"]["Data"]["UAC"]["Value"];
	$aktuelleDaten[$prefix]["UAC"]["L1"] = isset($JSON_Daten["Body"]["Data"]["UAC_L1"]) ? $JSON_Daten["Body"]["Data"]["UAC_L2"]["Value"] : 0;
	$aktuelleDaten[$prefix]["UAC"]["L2"] = isset($JSON_Daten["Body"]["Data"]["UAC_L2"]) ? $JSON_Daten["Body"]["Data"]["UAC_L2"]["Value"] : 0;

    $aktuelleDaten[$prefix]["UDC"]["1"] = $JSON_Daten["Body"]["Data"]["UDC"]["Value"];
    $aktuelleDaten[$prefix]["UDC"]["2"] = $JSON_Daten["Body"]["Data"]["UDC_2"]["Value"];

    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
  }
  else {
    break;
  }


  // GetPowerFlowRealtimeData
  $URL  = "/solar_api/v1/GetPowerFlowRealtimeData.fcgi";

  $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
  if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
    // Es handelt sich um gültige Daten
    $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);
	
	$prefix = "PowerFlow";
    $aktuelleDaten[$prefix]["Power"]["AC"] = $JSON_Daten["Body"]["Data"]["Inverters"][$WR_Adresse]["P"];
	
    $aktuelleDaten[$prefix]["Backup_Mode"] = $JSON_Daten["Body"]["Data"]["Site"]["BackupMode"];
    $aktuelleDaten[$prefix]["Battery_Standby"] = $JSON_Daten["Body"]["Data"]["Site"]["BatteryStandby"];

    $aktuelleDaten[$prefix]["Energy"]["Today"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Day"];
    $aktuelleDaten[$prefix]["Energy"]["Year"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Year"];
    $aktuelleDaten[$prefix]["Energy"]["Total"] = $JSON_Daten["Body"]["Data"]["Site"]["E_Total"];
	
    $aktuelleDaten[$prefix]["Meter_Location"] = $JSON_Daten["Body"]["Data"]["Site"]["Meter_Location"];
    $aktuelleDaten[$prefix]["Mode"] = $JSON_Daten["Body"]["Data"]["Site"]["Mode"];
    
	$aktuelleDaten[$prefix]["Power"]["Grid"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Grid"];
    $aktuelleDaten[$prefix]["Power"]["Load"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Load"];
    $aktuelleDaten[$prefix]["Power"]["Akku"] = $JSON_Daten["Body"]["Data"]["Site"]["P_Akku"];
    $aktuelleDaten[$prefix]["Power"]["PV"] = $JSON_Daten["Body"]["Data"]["Site"]["P_PV"];
    
	$aktuelleDaten[$prefix]["Rel"]["Autonomy"] = $JSON_Daten["Body"]["Data"]["Site"]["rel_Autonomy"];
    $aktuelleDaten[$prefix]["Rel"]["SelfConsumption"] = isset($JSON_Daten["Body"]["Data"]["Site"]["rel_SelfConsumption"]) ? $JSON_Daten["Body"]["Data"]["Site"]["rel_SelfConsumption"] : 0;
	
    // Timestamp
	$aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
	
	$timeStampPowerFlow = strtotime($JSON_Daten["Head"]["Timestamp"]);
  }
  else {
    break;
  }


if ($GetMeterRealtimeData === true)
{
  // Meter
  if ($aktuelleDaten["DeviceInfo"]["Meter"] == 1)  {
    // GetMeterRealtimeData
    $URL  = "/solar_api/v1/GetMeterRealtimeData.cgi";
    $URL .= "?Scope=System";
    $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);
    if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
      // Es handelt sich um gültige Daten
      $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);
	  
      $prefix = "Meter";
      $aktuelleDaten[$prefix]["ACBRIDGE_CURRENT_ACTIVE_MEAN_01_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_CURRENT_ACTIVE_MEAN_01_F32"];
      $aktuelleDaten[$prefix]["ACBRIDGE_CURRENT_ACTIVE_MEAN_02_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_CURRENT_ACTIVE_MEAN_02_F32"];
      $aktuelleDaten[$prefix]["ACBRIDGE_CURRENT_ACTIVE_MEAN_03_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_CURRENT_ACTIVE_MEAN_03_F32"];
      $aktuelleDaten[$prefix]["ACBRIDGE_CURRENT_AC_SUM_NOW_F64"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_CURRENT_AC_SUM_NOW_F64"];
      $aktuelleDaten[$prefix]["ACBRIDGE_VOLTAGE_MEAN_12_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_VOLTAGE_MEAN_12_F32"];
      $aktuelleDaten[$prefix]["ACBRIDGE_VOLTAGE_MEAN_23_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_VOLTAGE_MEAN_23_F32"];
      $aktuelleDaten[$prefix]["ACBRIDGE_VOLTAGE_MEAN_31_F32"] = $JSON_Daten["Body"]["Data"]["0"]["ACBRIDGE_VOLTAGE_MEAN_31_F32"];
      $aktuelleDaten[$prefix]["COMPONENTS_MODE_ENABLE_U16"] = $JSON_Daten["Body"]["Data"]["0"]["COMPONENTS_MODE_ENABLE_U16"];
      $aktuelleDaten[$prefix]["COMPONENTS_MODE_VISIBLE_U16"] = $JSON_Daten["Body"]["Data"]["0"]["COMPONENTS_MODE_VISIBLE_U16"];
      $aktuelleDaten[$prefix]["COMPONENTS_TIME_STAMP_U64"] = $JSON_Daten["Body"]["Data"]["0"]["COMPONENTS_TIME_STAMP_U64"];

      $aktuelleDaten[$prefix]["Details_Manufacturer"] = $JSON_Daten["Body"]["Data"]["0"]["Details"]["Manufacturer"];
      $aktuelleDaten[$prefix]["Details_Model"] = $JSON_Daten["Body"]["Data"]["0"]["Details"]["Model"];
      $aktuelleDaten[$prefix]["Details_Serial"] = $JSON_Daten["Body"]["Data"]["0"]["Details"]["Serial"];
	  
      $aktuelleDaten[$prefix]["GRID_FREQUENCY_MEAN_F32"] = $JSON_Daten["Body"]["Data"]["0"]["GRID_FREQUENCY_MEAN_F32"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYACTIVE_ABSOLUT_MINUS_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYACTIVE_ABSOLUT_MINUS_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYACTIVE_ABSOLUT_PLUS_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYACTIVE_ABSOLUT_PLUS_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYACTIVE_CONSUMED_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYACTIVE_CONSUMED_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYACTIVE_PRODUCED_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYACTIVE_PRODUCED_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYREACTIVE_CONSUMED_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYREACTIVE_CONSUMED_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_ENERGYREACTIVE_PRODUCED_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_ENERGYREACTIVE_PRODUCED_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_FACTOR_POWER_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_FACTOR_POWER_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_FACTOR_POWER_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_FACTOR_POWER_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_FACTOR_POWER_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_FACTOR_POWER_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_FACTOR_POWER_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_FACTOR_POWER_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_MEAN_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_MEAN_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_MEAN_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_MEAN_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_MEAN_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_MEAN_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERACTIVE_MEAN_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERACTIVE_MEAN_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_MEAN_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_MEAN_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_MEAN_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_MEAN_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERAPPARENT_MEAN_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERAPPARENT_MEAN_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERREACTIVE_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERREACTIVE_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERREACTIVE_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERREACTIVE_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERREACTIVE_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERREACTIVE_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_POWERREACTIVE_MEAN_SUM_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_POWERREACTIVE_MEAN_SUM_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VALUE_LOCATION_U16"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VALUE_LOCATION_U16"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_03_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_MEAN_01_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_MEAN_01_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_MEAN_02_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_MEAN_02_F64"];
      $aktuelleDaten[$prefix]["SMARTMETER_VOLTAGE_MEAN_03_F64"] = $JSON_Daten["Body"]["Data"]["0"]["SMARTMETER_VOLTAGE_MEAN_03_F64"];
	  
      // Timestamp
	  $aktuelleDaten[$prefix]["Timestamp"] = $JSON_Daten["Head"]["Timestamp"];
      // $funktionen->log_schreiben(print_r($aktuelleDaten[$prefix]["SMARTMETER_ENERGYACTIVE_CONSUMED_SUM_F64"],1),"   ",5);
    }
    else {
      break;
    }

  }
}


  // not tested
  if ($aktuelleDaten["DeviceInfo"]["Ohmpilot"] == 1)  {

    $URL  = "/solar_api/v1/GetOhmPilotRealtimeData.cgi";
    $URL .= "?Scope=System";
    $JSON_Daten = $funktionen->read($WR_IP,$WR_Port,$URL);

    if (isset($JSON_Daten["Head"]["Status"]["Code"]) and $JSON_Daten["Head"]["Status"]["Code"] == 0) {
      // Es handelt sich um gültige Daten
      $funktionen->log_schreiben(print_r($JSON_Daten,1),"   ",10);

      // if (isset($JSON_Daten["Body"]["Data"]["0"])) {  // Channel 0
        // $aktuelleDaten["Ohmpilot_EnergieGesamt"] = $JSON_Daten["Body"]["Data"]["0"]["EnergyReal_WAC_Sum_Consumed"];
        // $aktuelleDaten["Ohmpilot_Wirkleistung"] = $JSON_Daten["Body"]["Data"]["0"]["PowerReal_PAC_Sum"];
      // }
    }
    else {
      break;
    }

  }

  // not tested
  if ($aktuelleDaten["DeviceInfo"]["Storage"] == 1)  {
	//
  }
  
  // $aktuelleDaten["WattstundenGesamtJahr"] = $aktuelleDaten["Meter_Energy_Produced"];
  // $aktuelleDaten["WattstundenGesamt"] = $aktuelleDaten["Meter_Energy_Produced"];

  foreach($aktuelleDaten as $key=>$wert) {
    if (empty($wert))  {
      $aktuelleDaten[$key] = 0;
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
  // $FehlermeldungText = $funktionen->fronius_getFehlerString($aktuelleDaten["ErrorCodes"]);

  /****************************************************************************
  //  Wird für die HomeMatic Anbindung benötigt
  ****************************************************************************/

  // $aktuelleDaten["Solarleistung"] = ($aktuelleDaten["Solarspannung"] * $aktuelleDaten["Solarstrom"]);
  // $aktuelleDaten["AC_Leistung"] = $aktuelleDaten["AC_Wirkleistung"];

  /****************************************************************************
  //  Leistung
  ****************************************************************************/

  if (isset($aktuelleDaten["PowerFlow"]["Power"]["Grid"])) {
    if ($aktuelleDaten["PowerFlow"]["Power"]["Grid"] < 0 ) {
      $aktuelleDaten["Power"]["GridFeed"] = abs($aktuelleDaten["PowerFlow"]["Power"]["Grid"]);
      $aktuelleDaten["Power"]["GridPurchase"] = 0;
      // $aktuelleDaten["Power"]["SelfConsumption"] = abs($aktuelleDaten["PowerFlow"]["Power"]["Load"]);
      $aktuelleDaten["Power"]["SelfConsumption"] = $aktuelleDaten["PowerFlow"]["Power"]["PV"] - $aktuelleDaten["Power"]["GridFeed"]; // wie solarweb ???
      // $aktuelleDaten["Power"]["TotalConsumption"] = abs($aktuelleDaten["PowerFlow"]["Power"]["Load"]);
      $aktuelleDaten["Power"]["TotalConsumption"] = $aktuelleDaten["Power"]["SelfConsumption"]; // wie solarweb ???
    }
    else {
      $aktuelleDaten["Power"]["GridFeed"] = 0;
      $aktuelleDaten["Power"]["GridPurchase"] = $aktuelleDaten["PowerFlow"]["Power"]["Grid"];
      // $aktuelleDaten["Power"]["SelfConsumption"] = $aktuelleDaten["PowerFlow"]["Power"]["AC"];
      $aktuelleDaten["Power"]["SelfConsumption"] = $aktuelleDaten["PowerFlow"]["Power"]["PV"]; // wie solarweb rechnet
      // $aktuelleDaten["Power"]["TotalConsumption"] = abs($aktuelleDaten["PowerFlow"]["Power"]["Load"]);
      $aktuelleDaten["Power"]["TotalConsumption"] = $aktuelleDaten["Power"]["GridPurchase"] + $aktuelleDaten["Power"]["SelfConsumption"]; // wie solarweb ???
    }
    $aktuelleDaten["Power"]["AC"] = $aktuelleDaten["PowerFlow"]["Power"]["AC"];
    $aktuelleDaten["Power"]["PV"] = $aktuelleDaten["PowerFlow"]["Power"]["PV"];
  }

  /****************************************************************************
  //  Energie
  ****************************************************************************/

  $timeStampEnergy["Day"] = date("j",$timeStampPowerFlow);
  $timeStampEnergy["Month"] = date("n",$timeStampPowerFlow);
  $timeStampEnergy["Year"] = date("Y",$timeStampPowerFlow);

  foreach($energyDataSpanDayMonthYear as $span) {
      onTimestampChangedResetCounter($energyData,$span,$timeStampEnergy[$span]);
  }
  
  foreach($energyDataType as $type) {
	foreach($energyDataSpan as $span) {
		// linear interpolated energy 
		calculateEnergyLinearInterpolated($energyData[$type][$span],$aktuelleDaten["Power"][$type],$timeStampPowerFlow,$energyData[$type]["Power"],$energyData["Timestamp"]["epoche"]);
		// store power for next calculation
		$energyData[$type]["Power"] = $aktuelleDaten["Power"][$type];
	}
  }
  $energyData["Timestamp"]["epoche"] = $timeStampPowerFlow;

  /****************************************************************************
  //  Energie vom SmartMeter
  ****************************************************************************/
  $energyGrid["GridConsumed"] = $aktuelleDaten["Meter"]["SMARTMETER_ENERGYACTIVE_CONSUMED_SUM_F64"];
  $energyGrid["GridProduced"] = $aktuelleDaten["Meter"]["SMARTMETER_ENERGYACTIVE_PRODUCED_SUM_F64"];
  
  foreach($energyDataTypeMeter as $type) {
      foreach($energyDataSpanYearTotal as $span) {
		  // if (!isset($energyData[$type][$span])) {
              // $energyData[$type][$span] = $energyGrid[$type];
              // $energyData[$type][$span] = 0;
		  // }
	  }
  }

  foreach($energyDataSpanDayMonthYear as $span) {
	  onTimestampChangedSetEnergyCounterToActualValue($energyData,$energyGrid,$span,$timeStampEnergy[$span]);
  }

  foreach($energyDataTypeMeter as $type) {
      foreach($energyDataSpanDayMonthYearTotal as $span) {
          $aktuelleDaten["Energy"][$type][$span] = $energyGrid[$type] - $energyData[$type][$span];
	  }
      $funktionen->log_schreiben(print_r($aktuelleDaten["Energy"][$type],1),"*- ",5);
  }

  foreach($energyDataSpanDayMonthYear as $span) {
      onTimestampChangedUpdate($energyData,$span,$timeStampEnergy[$span]);
  }

  /****************************************************************************
  //  Die Daten werden für die Speicherung vorbereitet.
  ****************************************************************************/
  $aktuelleDaten["Produkt"] = "Fronius Symo Gen24";
  $aktuelleDaten["Regler"] = $Regler;
  $aktuelleDaten["Objekt"] = $Objekt;


  $funktionen->log_schreiben(print_r($aktuelleDaten,1),"*- ",10);


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
  $aktuelleDaten["Timestamp"] = $timeStampPowerFlow;
  $aktuelleDaten["Monat"]     = date("n",$timeStampPowerFlow);
  $aktuelleDaten["Woche"]     = date("W",$timeStampPowerFlow);
  $aktuelleDaten["Wochentag"] = strftime("%A",$timeStampPowerFlow);
  $aktuelleDaten["Datum"]     = date("d.m.Y",$timeStampPowerFlow);
  $aktuelleDaten["Uhrzeit"]   = date("H:i:s",$timeStampPowerFlow);


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
  }
  if ($InfluxDB_local) {
    $rc = $funktionen->influx_local($aktuelleDaten);
  }
  // $queryData = $funktionen->query_erzeugen($aktuelleDaten);
  // $funktionen->log_schreiben(print_r($queryData,1),"*- ",7);


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
	$DeltaTime = ($MaxScriptRuntime - (time() - $Start))/($Wiederholungen-$i+1);
	$MicroNow = microtime(true);
	// $MicroDelta = (57 - ($MicroNow - $MicroStart))/($Wiederholungen-$i);
	$MicroDelta = ($MaxScriptRuntime - ($MicroNow - $MicroStart))/($Wiederholungen-$i+1);
	$MicroUntil = $MicroNow + $MicroDelta;
    $funktionen->log_schreiben("Schleife: ".($i)." Zeitspanne: ".(floor($DeltaTime))." DeltaTime: ".$DeltaTime." MicroDelta: ".$MicroDelta." MicroUntil: ".$MicroUntil,"   ",5);
    // $funktionen->log_schreiben("Schleife: ".($i)." Zeitspanne: ".(floor((57 - (time() - $Start))/($Wiederholungen-$i+1))),"   ",9);
    // sleep(floor($DeltaTime));
	// usleep(floor($MicroDelta*1000000));
	// usleep(floor($MicroDelta*825000));
	// usleep(floor($MicroDelta*820000));
	// usleep(floor($MicroDelta*600000)); // 24
    if ($i < $Wiederholungen) {
	  // usleep(floor($MicroDelta*560500));
	  usleep(floor($MicroDelta*440000));
	}
	// time_sleep_until($MicroUntil);
  }
  if ($Wiederholungen <= $i or $i >= $MaxWiederholungen) {
    $funktionen->log_schreiben("OK. Daten gelesen.","   ",9);
    $funktionen->log_schreiben("Schleife ".$i." Ausgang...","   ",8);
    break;
  }

  $i++;
} while (($Start + $MaxScriptRuntime) > time());



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


writeIniFile($energyDataFile,$energyData);
$funktionen->log_schreiben("energyData => ".print_r($energyData,1),"*- ",8);

Ausgang:


$funktionen->log_schreiben("-------------   Stop   fronius_symo_gen24.php    --------------- ","|--",6);

return;




?>
