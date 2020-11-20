<?php
//  Funktionen für das Motorboot Programm geschrieben von Ulrich Kunz 2015-
//
//
class funktionen{

  function funktionenIn(){
    return true;
  }

  function openUSB($Device){
    $res = fopen($Device,"r+");
    //stream_set_timeout ( $res, 1, 0 );

    return $res;
  }

  function closeUSB($Device){
    $res = fclose($Device);
    return $res;
  }

  function _exec($cmd, &$out = null)   {
	$desc = array(
		1 => array("pipe", "w"),
		2 => array("pipe", "w")
	);

	$proc = proc_open($cmd, $desc, $pipes);

    $ret = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	$retVal = proc_close($proc);

	if (func_num_args() == 2) $out = array($ret, $err);
	return $retVal;
  }

  /******************************************************************
  //
  //
  ******************************************************************/
  function usb_lesen($Device,$Befehl='') {

    $CR = "\n"; // CR LF -> eventuell löschen
    $Antwort = "";

    stream_set_blocking($Device, false);  // Es wird auf keine Daten gewartet.

    if (empty($Befehl)) {
      $Antwort = fgets($Device,8192);
      $Dauer = 0;  // nur 1 Durchlauf
    }
    else {
      $Dauer = 4;  // 4 Sekunden

      $rc = fwrite($Device,$Befehl.$CR);
      usleep(500000);  // 1/2 Sekunde
    }

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";

    do {

      $Antwort = fgets($Device,4096);

      if (trim($Antwort) == "") {
        // echo "Nichts empfangen .. \n";
        usleep(50000);
        $Antwort = "";
      }
      else {
        $Ergebnis .= $Antwort;
        $this->log_schreiben(time()." ".bin2hex($Ergebnis)."\n",10);
        if (substr($Ergebnis,0,18) == "solar.pc.senddata." and strlen(bin2hex($Ergebnis)) == 74 ) {
          $Dauer = 0; // Ausgang vorbereiten..
        }

      }



    } while (($Timestamp + $Dauer) > time());


    stream_set_blocking($Device, true);

    if (strlen(bin2hex($Ergebnis)) == 74) {
      return $Ergebnis;
    }

    return false;
  }


  function tageslicht($Ort='hamburg') {
    // Ist an dem Ort gerade Sonnenaufgang / Sonnenuntergang?
    $Daemmerung = 0;  // 3600 = 1 Stunde  600 = 10 Minuten

    switch(strtolower($Ort)) {
      case "essen":
        $Breite = 51.4556432;
        $Laenge = 7.01155520;
        break;

      case "hamburg":
        $Breite = 53.5510846;
        $Laenge = 9.99368180;
        break;

      case "bremerhaven":
        $Breite = 53.547748;
        $Laenge = 8.5700350;
        break;

      case "hirschau":
        $Breite = 49.550412;
        $Laenge = 11.947207;
        break;

      case "llucmajor":
        $Breite = 39.476000;
        $Laenge = 2.933000;
        break;

      case "würzburg":
        $Breite = 49.683385;
        $Laenge = 10.114229;
        break;

      default:
        $Breite = 53.5510846;
        $Laenge = 9.99368180;
        break;
    }

    $now = time();
    if (date("I")) {
      $gmt_offset = 2;
    }
    else {
      $gmt_offset = 1;
    }
    $zenith = 50/60;
    $zenith = $zenith + 90;
    $sunset = date_sunset($now, SUNFUNCS_RET_TIMESTAMP, $Breite, $Laenge, $zenith, $gmt_offset);
    $sunrise= date_sunrise($now, SUNFUNCS_RET_TIMESTAMP, $Breite, $Laenge, $zenith, $gmt_offset);

    if (time() > ($sunrise-$Daemmerung) and time() < ($sunset+$Daemmerung)) {
      return true;
    }
    return false;
  }


  /**************************************************************************
  //  Test, ob die remote Influx Datenbank zu erreichen ist
  //  Es wird die Influx Datenbank kontaktiert!
  //  Auf dem remote Server wird kein Apache benötigt.
  **************************************************************************/
  function influx_remote_test()   {
    Global $InfluxAdresse,$InfluxPort;

    // Damit sich der remote Datenbank Zugriff etwas verteilt.
    usleep(rand(100000,2000000));

    $timeout = 5.5;  // 5.1 ist ein guter Wert.
    $ch = curl_init();
    curl_setopt ( $ch, CURLOPT_URL, "http://".$InfluxAdresse );
    curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt ( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
    curl_setopt ( $ch, CURLOPT_PORT, $InfluxPort );
    curl_setopt ( $ch, CURLOPT_TIMEOUT, $timeout );
    $http_respond = curl_exec($ch);
    $http_respond = trim( strip_tags( $http_respond ) );
    $rc = curl_getinfo( $ch );
    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $timeout = curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
    curl_close( $ch );
    if ($rc["primary_port"] == $InfluxPort) {
      if  ( $http_code < "405" )   {
        $this->log_schreiben("Test remote InfluxDB - Dauer: ".$timeout." Sekunden","o  ",8);
        $this->log_schreiben("URL konnte erreicht werden. Http Code: ".$http_code,"o  ",9);
        return true;
      }
    }
    $this->log_schreiben("URL konnte nicht erreicht werden.","!  ",5);
    $this->log_schreiben("URL: http://".$InfluxAdresse,"!  ",9);
    $this->log_schreiben("Test Domain - Dauer: ".$timeout." Sekunden","!  ",5);
    $this->log_schreiben("Test Domain - RC: ".print_r($rc,1),"!  ",9);
    return false;
  }


  /**************************************************************************
  //  Daten in die lokale Influx Datenbank schreiben
  //  Folgende Spalten können übertragen werden:  (Je nach Regler)
  //
  //  [Info]
  //  Firmware
  //  Produkt
  //  Objekt
  //  Timestamp
  //  Woche
  //  Wochentag
  //  Datum
  //  Uhrzeit
  //  [Netz]
  //  Spannung
  //  Frequenz
  //  [AC]
  //  Spannung
  //  Frequenz
  //  Scheinleistung
  //  Wirkleistung
  //  Ausgangslast
  //  [Batterie]
  //  Spannung
  //  Spannung_WR
  //  Ladestrom
  //  Kapazitaet
  //  Entladestrom
  //  Bulk_Minuten
  //  Absorption_Minuten
  //  Float_Minuten
  //  maxAmpHeute
  //  [PV]
  //  Strom
  //  Spannung
  //  Leistung
  //  maxWattHeute
  //  maxVoltHeute
  //  [Service]
  //  Temperatur
  //  Laststatus			// ON / OFF
  //  Ladestatus			// 0,1,2,3,4,5
  //  Solar_Ladestatus  	// ON / OFF
  //  AC_Ladestatus		// ON / OFF
  //  Modus			    // P,S,L,B,F,H
  //  Device_Status		// ON / OFF
  //  Fehlercode
  //  Warnungen
  //  [Summen]
  //  Wh_Gesamt
  //  Wh_Heute
  //
  //
  //
  //
  //
  **************************************************************************/
  function influx_local($daten)   {

    $query = "";
    if (!isset($daten["InfluxDBLokal"])) {
      $daten["InfluxDBLokal"] = "solaranzeige";
    }

    //  Jetzt müssen die Daten in die lokale InfluxDB übertragen werden.
    //
    //  Zuerst die Statistikdaten übertragen. Einmal am Tage um 23:58 Uhr.
    if (date("i") == '00' or date("i") == '10' or date("i") == '20' or date("i") == '30' or date("i") == '40' or date("i") == '50') {
    // if (1 == 1) {
      //$this->log_schreiben("Aktuelle Statistik:\n".print_r($daten,1),"   ",9);
      $this->log_schreiben("Alle 10 Minuten werden die Statistikdaten übertragen.","   ",5);

      $query  = "Statistik Bezeichnung=\"WhTag\",Datum=\"".$daten['Datum']."\",Woche=".$daten['Woche'].",Monat=".$daten['Monat'].",";
      $query .= "Wert=".$daten["WattstundenGesamtHeute"].",Wochentag=\"".$daten['Wochentag']."\"";

      $ch = curl_init('http://localhost/write?db='.$daten["InfluxDBLokal"].'&precision=s');

      $i = 1;
      do {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);                //timeout in second s
        curl_setopt($ch, CURLOPT_PORT, "8086");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $rc_info = curl_getinfo ($ch);
        $Ausgabe = json_decode($result,true);

        if (curl_errno($ch)) {
          $this->log_schreiben("Curl Fehler[1]! Daten nicht zur lokalen InfluxDB ".$daten["InfluxDBLokal"]." gesendet! Curl ErrNo. ".curl_errno($ch),"   ",5);
        }
        if ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
          $this->log_schreiben("Daten zur lokalen InfluxDB  gesendet. ".$daten["WattstundenGesamtHeute"]." Wh\n".print_r($rc_info,1),"*  ",9);
          break;
        }
        elseif ($rc_info["http_code"] == 401) {
          $funktionen->log_schreiben("Influx UserID oder Kennwort ist falsch.","*  ",5);
          break;
        }
        elseif(empty($Ausgabe["error"])) {
          $this->log_schreiben("Lokale InfluxDB Fehler -> nochmal versuchen.","   ",5);
          $i++;
          continue;
        }
        $this->log_schreiben("Daten nicht zur lokalen InfluxDB [ ".$daten["InfluxDBLokal"]." ] gesendet! => [ ".$Ausgabe["error"]." ]","   ",5);
        $this->log_schreiben("InfluxDB  => [ ".$query." ]","   ",5);
        $this->log_schreiben("Daten => [ ".print_r($daten,1)." ]","   ",9);
        $this->log_schreiben("Daten nicht zur lokalen InfluxDB gesendet! info: ".var_export($rc_info,1),"   ",5);
        $i++;
        sleep(5);
      } while ($i < 3);

      curl_close($ch);
      unset($ch);


    }
    // Zur Zeit ausgeschaltet.
    if(1 == 1)  {

      $this->log_schreiben("Aktuelle Daten: \n".print_r($daten,1),"   ",9);

      $query = $this->query_erzeugen($daten);

      $ch = curl_init('http://localhost/write?db='.$daten["InfluxDBLokal"].'&precision=s');

      $i = 1;
      do {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);                //timeout in second s
        curl_setopt($ch, CURLOPT_PORT, "8086");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $rc_info = curl_getinfo ($ch);
        $Ausgabe = json_decode($result,true);

        if (curl_errno($ch)) {
          $this->log_schreiben("Curl Fehler[2]! Daten nicht zur lokalen InfluxDB ".$daten["InfluxDBLokal"]." gesendet! Curl ErrNo. ".curl_errno($ch),"   ",5);
        }
        if ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
          $this->log_schreiben("Daten zur lokalen InfluxDB [ ".$daten["InfluxDBLokal"]." ] gesendet. ","*  ",7);
          break;
        }
        elseif ($rc_info["http_code"] == 401) {
          $funktionen->log_schreiben("Influx UserID oder Kennwort ist falsch.","*  ",5);
          break;
        }
        elseif(empty($Ausgabe["error"])) {
          $this->log_schreiben("InfluxDB Fehler -> nochmal versuchen.","   ",5);
          $i++;
          continue;
        }
        $this->log_schreiben("InfluxDB  => [ ".$query." ]","   ",5);
        $this->log_schreiben("Daten => [ ".print_r($daten,1)." ]","   ",9);
        $this->log_schreiben("Daten nicht zur lokalen InfluxDB gesendet! info: ".var_export($rc_info,1),"   ",5);
        $i++;
        sleep(5);
      } while ($i < 3);


      curl_close($ch);

    }

    return true;
  }


  /**************************************************************************
  //  Daten in die remote Influx Datenbank schreiben
  //
  //
  **************************************************************************/
  function influx_remote($daten)   {

    $query = "";

    //  Jetzt müssen die Daten in die InfluxDB übertragen werden.
    //
    //

    //  Zuerst die Statistikdaten übertragen. Einmal am Tage um 00:00 Uhr.
    if (date("i") == '00' or date("i") == '10' or date("i") == '20' or date("i") == '30' or date("i") == '40' or date("i") == '50') {
      // $this->log_schreiben("Aktuelle Statistik:\n".print_r($daten,1),"   ",9);
      $this->log_schreiben("Alle 10 Minuten werden die Statistikdaten remote übertragen.","   ",5);

      $query  = "Statistik Bezeichnung=\"WhTag\",Datum=\"".$daten['Datum']."\",Woche=".$daten['Woche'].",Monat=".$daten['Monat'].",";
      $query .= "Wert=".$daten["WattstundenGesamtHeute"].",Wochentag=\"".$daten['Wochentag']."\"";

      if (isset($daten["InfluxSSL"]) and $daten["InfluxSSL"] == true) {
        $ch = curl_init('https://'.$daten["InfluxAdresse"].'/write?db='.$daten["InfluxDBName"].'&precision=s');
      }
      else {    
        $ch = curl_init('http://'.$daten["InfluxAdresse"].'/write?db='.$daten["InfluxDBName"].'&precision=s');
      }
 
      $i = 1;
      do {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);                //timeout in second s
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
        curl_setopt($ch, CURLOPT_PORT, $daten["InfluxPort"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        if (!empty($daten["InfluxUser"]) and !empty($daten["InfluxPassword"])) {
          curl_setopt($ch, CURLOPT_USERPWD, $daten["InfluxUser"].":".$daten["InfluxPassword"]);
        }
        if (isset($daten["InfluxSSL"]) and $daten["InfluxSSL"] == true) {
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $rc_info = curl_getinfo ($ch);
        $Ausgabe = json_decode($result,true);

        if (curl_errno($ch)) {
          $this->log_schreiben("Curl Fehler! Daten nicht zur entfernten InfluxDB gesendet! Curl ErrNo. ".curl_errno($ch),"   ",5);
        }
        if ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
          $this->log_schreiben("Daten zur InfluxDB  gesendet. ".round($daten["WattstundenGesamtHeute"])." Wh\n".print_r($rc_info,1),"*  ",9);
          break;
        }
        elseif ($rc_info["http_code"] == 401) {
          $funktionen->log_schreiben("Influx UserID oder Kennwort ist falsch.","*  ",5);
          break;
        }
        elseif(empty($Ausgabe["error"])) {
          $this->log_schreiben("InfluxDB Fehler -> nochmal versuchen.","   ",5);
          $i++;
          continue;
        }
        $this->log_schreiben("InfluxDB  => [ ".$query." ]","   ",5);
        $this->log_schreiben("Daten => [ ".print_r($daten,1)." ]","   ",9);
        $this->log_schreiben("Daten nicht zur InfluxDB gesendet! info: ".var_export($rc_info,1),"   ",5);
        $i++;
        sleep(1);
      } while ($i < 3);

      curl_close($ch);
      unset($ch);


    }
    // Nur beim Victron Laderegler wird Nachts nicht gesendet.
    if($this->tageslicht("hamburg") or $daten["InfluxDaylight"] === false)  {

      $this->log_schreiben("Aktuelle Daten: \n".print_r($daten,1),"   ",9);

      $query = $this->query_erzeugen($daten);

      if (isset($daten["InfluxSSL"]) and $daten["InfluxSSL"] == true) {
        $ch = curl_init('https://'.$daten["InfluxAdresse"].'/write?db='.$daten["InfluxDBName"].'&precision=s');
      }
      else {      
        $ch = curl_init('http://'.$daten["InfluxAdresse"].'/write?db='.$daten["InfluxDBName"].'&precision=s');
      }



      $i = 1;
      do {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);                //timeout in second s
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
        curl_setopt($ch, CURLOPT_PORT, $daten["InfluxPort"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        if (!empty($daten["InfluxUser"]) and !empty($daten["InfluxPassword"])) {
          curl_setopt($ch, CURLOPT_USERPWD, $daten["InfluxUser"].":".$daten["InfluxPassword"]);
        }
        if (isset($daten["InfluxSSL"]) and $daten["InfluxSSL"] == true) {
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $rc_info = curl_getinfo ($ch);
        $Ausgabe = json_decode($result,true);

        if (curl_errno($ch)) {
          $this->log_schreiben("Curl Fehler! Daten nicht zur entfernten InfluxDB gesendet! No. ".curl_errno($ch),"   ",5);
        }
        if ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
          $this->log_schreiben("Daten zur entfernten InfluxDB [ ".$daten["InfluxDBName"]." ] gesendet. ","*  ",7);
          break;
        }
        elseif ($rc_info["http_code"] == 401) {
          $this->log_schreiben("Influx UserID oder Kennwort ist falsch.","*  ",5);
          break;
        }
        elseif(empty($Ausgabe["error"])) {
          $this->log_schreiben("InfluxDB Fehler -> nochmal versuchen.","   ",5);
          $i++;
          continue;
        }
        $this->log_schreiben("Daten nicht zur InfluxDB gesendet! => [ ".$Ausgabe["error"]." ]","   ",5);
        $this->log_schreiben("InfluxDB  => [ ".$query." ]","   ",5);
        $this->log_schreiben("Daten => [ ".print_r($daten,1)." ]","   ",9);
        $this->log_schreiben("Daten nicht zur InfluxDB gesendet! info: ".var_export($rc_info,1),"   ",5);
        $i++;
        sleep(1);
      } while ($i < 3);


      curl_close($ch);

    }

    return true;
  }


  /**************************************************************************
  //  Hier werden Demo Daten vorbereitet.
  //
  //
  **************************************************************************/

  function demo_daten_erzeugen($Regler){

    $aktuelleDaten = array (
        'Firmware' => 1.0,
        'Produkt'  => "Solaranzeige",
        'Batteriespannung' => 0,
        'Batterieentladestrom' => 0,
        'Batterieladestrom' => 0,
        'Batteriekapazitaet' => 0,
        'BatterieladestromMaxHeute' => 0,
        'Verbraucherstrom' => 0,
        'SolarspannungMaxHeute' => 0,
        'WattstundenGesamtHeute' => 0,
        'WattstundenGesamt' => 0,
        'AmperestundenGesamt' => 0,
        'Ladestatus' => 0,
        'Netzspannung' => 0,
        'Netzfrequenz' => 0,
        'AC_Ausgangsspannung' => 0,
        'AC_Ausgangsfrequenz' => 0,
        'AC_Scheinleistung' => 0,
        'AC_Wirkleistung' => 0,
        'AC_Ausgangslast' => 0,
        'AC_Ausgangsstrom' => 0,
        'AC_Leistung' => 0,
        'Ausgangslast' => 0,
        'Solarstrom' => 0,
        'Solarspannung' => 0,
        'Solarleistung' => 0,
        'maxWattHeute' => 0,
        'maxAmpHeute' => 0,
        'Temperatur' => 0,
        'Optionen' => 0,
        'Modus' => "B",
        'DeviceStatus' => 0,
        'ErrorCodes' => 0,
        'Regler' => $Regler,
        'Objekt' => "Solaranzeige",
        'Timestamp' => time(),
        'Monat' => date("n"),
        'Woche' => date("W"),
        'Wochentag' => strftime("%A",time()),
        'Datum' => date("d.m.Y"),
        'Uhrzeit' => date("H:i:s"),
        'Demodaten' => true,
        'InfluxAdresse' => 'localhost',
        'InfluxUser' =>  'admin',
        'InfluxPassword' => 'solaranzeige',
        'InfluxDBName' => 'solaranzeige'
    );


    return $aktuelleDaten;
  }


  /**************************************************************************
  //  Hier werden Demo Daten erzeugt.
  //
  //
  **************************************************************************/

  function query_erzeugen($daten){

    $Summe = 0;
    $now=time();
    $gmt_offset = 1+date("I");
    $zenith = 50/60;
    $zenith = $zenith + 90;
    $Sonnenaufgang = date_sunrise($now, SUNFUNCS_RET_TIMESTAMP, 50.1143999, 8.6585178, $zenith, $gmt_offset);

      $query = "";

      switch($daten["Regler"]) {

        case 1:                       // SCplus und SCDplus
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Entladestrom=".$daten["Batterieentladestrom"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Batterieladestrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Ah_Gesamt=".$daten["AmperestundenGesamt"];
          $query .= ",Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;


        case 2:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Ladestrom=".round($daten["Batterieladestrom"],2);
          $query .= ",Entladestrom=".round($daten["Batterieentladestrom"],2);
          $query .= ",Batteriestrom=".round($daten["BatteriestromGesamt"],2);
          $query .= ",SOC=".$daten["SOCProzent"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung1"];
          $query .= ",Spannung_String_1=".$daten["Solarspannung1"];
          $query .= ",Spannung_String_2=".$daten["Solarspannung2"];
          $query .= ",Strom=".round($daten["Solarstrom"],2);
          $query .= "\n";
          $query .= "Service ";
          $query .= "Ladestatus=".$daten["Lademodus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",StatusLast=".$daten["StatusLast"];
          $query .= ",Relais1=".$daten["Relais1"];
          $query .= ",Relais2=".$daten["Relais2"];
          $query .= ",TagNacht=".$daten["TagNacht"];
          $query .= ",Fehlercode=".$daten["Fehlerstatus"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Ah_Eingang_Heute=".$daten["EnergieEingangTag"];
          $query .= ",Ah_Eingang_Gesamt=".$daten["EnergieEingangGesamt"];
          $query .= ",Ah_Ausgang_Heute=".$daten["EnergieAusgangTag"];
          $query .= ",Ah_Ausgang_Gesamt=".$daten["EnergieAusgangGesamt"];
          break;


        case 3:                      // Tracer serie
        case 14:                     // Rover Laderegler
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Entladestrom=".$daten["Batterieentladestrom"];
          $query .= ",Entladeleistung=".$daten["Batterieentladeleistung"];
          $query .= ",Temperatur=".$daten["BatterieTemperatur"];
          $query .= ",SpannungMaxHeute=".$daten["BatterieMaxVoltHeute"];
          $query .= ",SpannungMinHeute=".$daten["BatterieMinVoltHeute"];
          $query .= ",SOC=".$daten["BatterieSOC"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          if ($daten["Regler"] == 3) {
            $query .= ",maxVoltHeute=".$daten["SolarspannungMaxHeute"];
          }
          else {
            $query .= ",maxAmpereHeute=".$daten["SolarstromMaxHeute"];
          }
          $query .= "\n";
          $query .= "Service ";
          $query .= "Ladestatus=".$daten["Ladestatus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          if ($daten["Regler"] == 3) {
            $query .= ",Optionen=".bindec($daten["Optionen"]);
          }
          else {
            $query .= ",Fehlercode=".bindec($daten["Fehlercode"]);
          }
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Verbrauch_Wh_Heute=".$daten["VerbrauchGesamtHeute"];
          $query .= ",Verbrauch_Wh_Gesamt=".$daten["VerbrauchGesamt"];
          break;


        case 4:                     // Victron Laderegler
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Entladestrom=".$daten["Batterieentladestrom"];
          $query .= ",maxAmpHeute=".$daten["BatterieladestromMaxHeute"];
          $query .= ",Temperatur=".$daten["BatterieSenseTemp"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",maxVoltHeute=".$daten["SolarspannungMaxHeute"];
          $query .= ",maxWattHeute=".$daten["maxWattHeute"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Ladestatus=".$daten["Ladestatus"];
          $query .= ",StatusLoadausgang=".$daten["LoadStatus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Optionen=".bindec($daten["Optionen"]);
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          break;


        case 5:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Device_Status=".$daten["Geraetestatus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;


        case 6:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",SpannungAux=".$daten["BatteriespannungAux"];
          $query .= ",Strom=".$daten["Batteriestrom"];
          $query .= ",Leistung=".$daten["Leistung"];
          $query .= ",Kapazitaet=".$daten["SOC"];
          $query .= ",Amperestunden=".$daten["Amperestunden"];
          $query .= ",EntladetiefeDurchschnitt=".$daten["EntladetiefeDurchschnitt"];
          $query .= ",EntladungMax=".$daten["EntladungMax"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Restlaufzeit=".$daten["TTG"];
          $query .= ",Ladestatus=".$daten["Ladestatus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",ZeitVollladung=".$daten["ZeitVollladung"];
          $query .= ",Ladezyklen=".$daten["Ladezyklen"];
          $query .= ",AnzahlSynchronisationen=".$daten["AnzahlSynchronisationen"];
          $query .= ",Alarm=".$daten["Alarm"];
          $query .= ",Relais=".$daten["Relais"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_GesamtLadung=".$daten["WattstundenGesamtLadung"];
          $query .= ",Wh_GesamtEntladung=".$daten["WattstundenGesamtEntladung"];
          $query .= ",AmperestundenGesamt=".$daten["AmperestundenGesamt"];
          break;


        case 7:
        case 26:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung=".$daten["Netzspannung"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Ausgangslast=".$daten["Ausgangslast"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Spannung_WR=".$daten["Batteriespannung"]; // muss noch geändert werden!
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
          $query .= ",Entladestrom=".$daten["Batterieentladestrom"]; ;
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          if (isset($daten["PV1_Spannung"])) {
            $query .= ",Spannung1=".$daten["PV1_Spannung"];
            $query .= ",Strom1=".$daten["PV1_Strom"];
            $query .= ",Leistung1=".$daten["PV1_Leistung"];
            $query .= ",Spannung2=".$daten["PV2_Spannung"];
            $query .= ",Strom2=".$daten["PV2_Strom"];
            $query .= ",Leistung2=".$daten["PV2_Leistung"];
          }
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Ladestatus=".$daten["Ladestatus"];
          if ($daten["Regler"] == 26) {
            $query .= ",Fehlermeldung=\"".$daten["Fehlermeldung"]."\"";
          }          						// *
          $query .= ",Modus=\"".$daten["Modus"]."\"";
          $query .= ",Device_Status=".$daten["DeviceStatus"];								// *
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Fehlercode=0";									// *
          $query .= ",Warnungen=".bindec($daten["Optionen"]);		    	// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;


        // Infini Solar V Serie
        case 8:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung=".$daten["Netzspannung"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Ausgangslast=".$daten["Ausgangslast"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Spannung_WR=".$daten["Batteriespannung"]; // muss noch geändert werden!
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
          $query .= ",Entladestrom=".$daten["Batterieentladestrom"]; ;
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung1"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",Spannung1=".$daten["Solarspannung1"];
          $query .= ",Spannung2=".$daten["Solarspannung2"];
          $query .= ",Leistung1=".$daten["Solarleistung1"];
          $query .= ",Leistung2=".$daten["Solarleistung2"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Laststatus=0";									// *
          $query .= ",Ladestatus=".$daten["Ladestatus1"];						// *
          $query .= ",Ladestatus2=".$daten["Ladestatus2"];						// *
          $query .= ",Stromrichtung_Batt=".$daten["Batteriestromrichtung"];								// *
          $query .= ",Stromrichtung_WR=".$daten["WR_Stromrichtung"];								// *
          $query .= ",Stromrichtung_Netz=".$daten["Netzstromrichtung"];								// *
          $query .= ",Modus=".$daten["Modus"];
          $query .= ",Device_Status=0";								// *
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",MPPT1_Temperatur=".$daten["MPPT1_Temperatur"];
          $query .= ",MPPT2_Temperatur=".$daten["MPPT2_Temperatur"];
          $query .= ",Fehlercode=".$daten["Fehlercode"];			// *
          $query .= ",Warnungen=".$daten["Warnungen"];				// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],2);
          break;

        // MPP Solar MPI 3 Phasen
        case 9:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung_R=".$daten["Netzspannung_R"];
          $query .= ",Spannung_S=".$daten["Netzspannung_S"];
          $query .= ",Spannung_T=".$daten["Netzspannung_T"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Ausgangsspannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Ausgangsspannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Ausgangsspannung_T"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Scheinleistung_R=".$daten["AC_Scheinleistung_R"];
          $query .= ",Scheinleistung_S=".$daten["AC_Scheinleistung_S"];
          $query .= ",Scheinleistung_T=".$daten["AC_Scheinleistung_T"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Wirkleistung_R=".$daten["AC_Wirkleistung_R"];
          $query .= ",Wirkleistung_S=".$daten["AC_Wirkleistung_S"];
          $query .= ",Wirkleistung_T=".$daten["AC_Wirkleistung_T"];
          $query .= ",Eingangsleistung=".$daten["AC_Eingangsleistung"];
          $query .= ",Eingangsleistung_R=".$daten["AC_Eingangsleistung_R"];
          $query .= ",Eingangsleistung_S=".$daten["AC_Eingangsleistung_S"];
          $query .= ",Eingangsleistung_T=".$daten["AC_Eingangsleistung_T"];
          $query .= ",Ausgangslast=".$daten["Ausgangslast"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Spannung_WR=".$daten["Batteriespannung"]; // muss noch geändert werden!
          $query .= ",Ladestrom=".$daten["Batteriestrom"];
          $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
          $query .= ",Entladestrom=".$daten["Batteriestrom"]; ;
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung1"];
          $query .= ",Spannung1=".$daten["Solarspannung1"];
          $query .= ",Spannung2=".$daten["Solarspannung2"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",Leistung1=".$daten["Solarleistung1"];
          $query .= ",Leistung2=".$daten["Solarleistung2"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Strom1=".$daten["Solarstrom1"];
          $query .= ",Strom2=".$daten["Solarstrom2"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Laststatus=0";
          $query .= ",Ladestatus=".$daten["Solar_Status1"];
          $query .= ",Ladestatus2=".$daten["Solar_Status2"];
          $query .= ",Stromrichtung_Batt=".$daten["Batteriestromrichtung"];
          $query .= ",Stromrichtung_WR=".$daten["WR_Stromrichtung"];
          $query .= ",Stromrichtung_Netz=".$daten["Netzstromrichtung"];
          $query .= ",Modus=".$daten["Modus"];
          $query .= ",Device_Status=0";								// *
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Batterie_Temperatur=".$daten["Batterie_Temperatur"];
          $query .= ",Fehlercode=".$daten["ErrorCodes"];			// *
          $query .= ",Warnungen=".$daten["Warnungen"];				// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],2);
          $query .= ",Wh_Gesamt_Monat=".$daten["WattstundenGesamtMonat"];
          $query .= ",Wh_Gesamt_Jahr=".$daten["WattstundenGesamtJahr"];
          $query .= ",kWh_Total=".$daten["KiloWattstundenTotal"];
          break;


        // SolarMax
        case 10:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Spannung_R=".$daten["AC_Ausgangsspannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Ausgangsspannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Ausgangsspannung_T"];
          $query .= ",Strom_R=".$daten["AC_Ausgangsstrom_R"];
          $query .= ",Strom_S=".$daten["AC_Ausgangsstrom_S"];
          $query .= ",Strom_T=".$daten["AC_Ausgangsstrom_T"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Spannung_String_1=".$daten["Solarspannung_String_1"];
          $query .= ",Spannung_String_2=".$daten["Solarspannung_String_2"];
          $query .= ",Spannung_String_3=".$daten["Solarspannung_String_3"];
          $query .= ",Strom_String_1=".$daten["Solarstrom_String_1"];
          $query .= ",Strom_String_2=".$daten["Solarstrom_String_2"];
          $query .= ",Strom_String_3=".$daten["Solarstrom_String_3"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Betriebsstunden=".$daten["Betriebsstunden"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Ausgangslast=".$daten["Ausgangslast"];
          $query .= ",Errorcode=".$daten["ErrorCodes"];
          $query .= ",Status=".$daten["Geraetestatus"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          $query .= ",Wh_Gesamt_Gestern=".$daten["WattstundenGesamtGestern"];
          $query .= ",Wh_Gesamt_Monat=".$daten["WattstundenGesamtMonat"];
          $query .= ",Wh_Gesamt_Jahr=".$daten["WattstundenGesamtJahr"];
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          break;


        // Phoenix Victron
        case 11:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Warnungen=".$daten["Warnungen"];
          $query .= ",Mode=".$daten["Mode"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;

        // Fronius Symo Wechselrichter
        case 12:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",Spannung_String_1=".$daten["Solarspannung_String_1"];
          $query .= ",Strom_String_1=".$daten["Solarstrom_String_1"];
          if (isset($daten["Solarspannung_String_2"])) {
            $query .= ",Spannung_String_2=".$daten["Solarspannung_String_2"];
            $query .= ",Strom_String_2=".$daten["Solarstrom_String_2"];
          }
          $query .= "\n";
          $query .= "Service ";
          $query .= "Device_Status=".$daten["Geraetestatus"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Fehlercode=".$daten["ErrorCodes"];
          $query .= ",ModulPVLeistung=".$daten["ModulPVLeistung"];
          $query .= ",Meter_Location=\"".$daten["Meter_Location"]."\"";
          $query .= ",Mode=\"".$daten["Mode"]."\"";
          $query .= ",Autonomie=".round($daten["Rel_Autonomy"],1);
          $query .= ",Eigenverbrauch=".$daten["Rel_SelfConsumption"];
          $query .= ",Autonomie=".$daten["Rel_Autonomy"];
          $query .= ",Akkustand_SOC=".$daten["Akkustand_SOC"];
          if (isset($daten["Gen24Status"])) {
            $query .= ",Gen24_Status=\"".$daten["Gen24Status"]."\"";
          }
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Wh_Gesamt_Jahr=".$daten["WattstundenGesamtJahr"];
          $query .= ",SummeWattstundenGesamtHeute=".round($daten["SummeWattstundenGesamtHeute"],1);
          $query .= ",SummeWattstundenGesamt=".round($daten["SummeWattstundenGesamt"],1);
          $query .= ",SummeWattstundeGesamtJahr=".round($daten["SummeWattstundenGesamtJahr"],1);
          $query .= ",SummePowerGrid=".$daten["SummePowerGrid"];
          $query .= ",SummePowerLoad=".round($daten["SummePowerLoad"],1);
          $query .= ",SummePowerAkku=".$daten["SummePowerAkku"];
          $query .= ",SummePowerPV=".$daten["SummePowerPV"];
          $query .= "\n";
          if ($daten["Meter"] == 1) {
            $query .= "Meter ";
            $query .= "Wirkleistung=".$daten["Meter_Wirkleistung"];
            $query .= ",Scheinleistung=".$daten["Meter_Scheinleistung"];
            $query .= ",Blindleistung=".$daten["Meter_Blindleistung"];
            $query .= ",EnergieProduziert=".$daten["Meter_EnergieProduziert"];
            $query .= ",EnergieVerbraucht=". $daten["Meter_EnergieVerbraucht"];
            $query .= ",Einspeisung=". $daten["Einspeisung"];
            $query .= ",Bezug=". $daten["Bezug"];
            $query .= ",Verbrauch=". $daten["Verbrauch"];

            $query .= "\n";
          }
          if ($daten["Ohmpilot"] == 1 and $daten["Gen24"] == false) {   //
            $query .= "Ohmpilot ";
            $query .= "Wirkleistung=".$daten["Ohmpilot_Wirkleistung"];
            $query .= ",EnergieGesamt=".$daten["Ohmpilot_EnergieGesamt"];
            $query .= "\n";
          }

          if ($daten["Storage"] == 1 and $daten["Gen24"] == false) {
            $query .= "Batterie ";
            $query .= "Max_Kapazitaet=".$daten["Batterie_Max_Kapazitaet"];
            $query .= ",Strom=".$daten["Batterie_Strom_DC"];
            $query .= ",Spannung=".$daten["Batterie_Spannung_DC"];
            $query .= ",Hersteller=\"".$daten["Batterie_Hersteller"]."\"";
            $query .= ",Seriennummer=\"". $daten["Batterie_Seriennummer"]."\"";
            $query .= ",LadestatusProzent=". $daten["Batterie_StateOfCharge_Relative"];
            $query .= ",Zellenstatus=". $daten["Batterie_Status_Batteriezellen"];
            $query .= ",Temperatur=". $daten["Batterie_Zellentemperatur"];
            $query .= "\n";
          }

        break;


        // Joulie-16 BMS
        case 13:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Z1V=".$daten["Zelle1Volt"];
          $query .= ",Z1S=".$daten["Zelle1Status"];
          $query .= ",Z2V=".$daten["Zelle2Volt"];
          $query .= ",Z2S=".$daten["Zelle2Status"];
          $query .= ",Z3V=".$daten["Zelle3Volt"];
          $query .= ",Z3S=".$daten["Zelle3Status"];
          $query .= ",Z4V=".$daten["Zelle4Volt"];
          $query .= ",Z4S=".$daten["Zelle4Status"];
          $query .= ",Z5V=".$daten["Zelle5Volt"];
          $query .= ",Z5S=".$daten["Zelle5Status"];
          $query .= ",Z6V=".$daten["Zelle6Volt"];
          $query .= ",Z6S=".$daten["Zelle6Status"];
          $query .= ",Z7V=".$daten["Zelle7Volt"];
          $query .= ",Z7S=".$daten["Zelle7Status"];
          $query .= ",Z8V=".$daten["Zelle8Volt"];
          $query .= ",Z8S=".$daten["Zelle8Status"];
          $query .= ",Z9V=".$daten["Zelle9Volt"];
          $query .= ",Z9S=".$daten["Zelle9Status"];
          $query .= ",Z10V=".$daten["Zelle10Volt"];
          $query .= ",Z10S=".$daten["Zelle10Status"];
          $query .= ",Z11V=".$daten["Zelle11Volt"];
          $query .= ",Z11S=".$daten["Zelle11Status"];
          $query .= ",Z12V=".$daten["Zelle12Volt"];
          $query .= ",Z12S=".$daten["Zelle12Status"];
          $query .= ",Z13V=".$daten["Zelle13Volt"];
          $query .= ",Z13S=".$daten["Zelle13Status"];
          $query .= ",Z14V=".$daten["Zelle14Volt"];
          $query .= ",Z14S=".$daten["Zelle14Status"];
          $query .= ",Z15V=".$daten["Zelle15Volt"];
          $query .= ",Z15S=".$daten["Zelle15Status"];
          $query .= ",Z16V=".$daten["Zelle16Volt"];
          $query .= ",Z16S=".$daten["Zelle16Status"];
          $query .= ",Strom=".$daten["Strom"];
          $query .= ",Spannung=".$daten["Spannung"];
          $query .= ",Kapazitaet=".$daten["Kapazitaet"];
          $query .= ",SOC=".$daten["SOC"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Balancing=".$daten["Balancing"];
          $query .= ",Fehlercode=\"".$daten["Fehlercode"]."\"";
          $query .= "\n";
          break;

        //  PolynTech US2000B
        case 15:
        case 41:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          for ($i = 1 ; $i <= $daten["Packs"]; $i++) {
            $query .= "Pack".$i." ";
            $query .= "Zellen=".$daten["Pack".$i."_Zellen"];
            $query .= ",Zelle1=".$daten["Pack".$i."_Zelle1"];
            $query .= ",Zelle2=".$daten["Pack".$i."_Zelle2"];
            $query .= ",Zelle3=".$daten["Pack".$i."_Zelle3"];
            $query .= ",Zelle4=".$daten["Pack".$i."_Zelle4"];
            $query .= ",Zelle5=".$daten["Pack".$i."_Zelle5"];
            $query .= ",Zelle6=".$daten["Pack".$i."_Zelle6"];
            $query .= ",Zelle7=".$daten["Pack".$i."_Zelle7"];
            $query .= ",Zelle8=".$daten["Pack".$i."_Zelle8"];
            $query .= ",Zelle9=".$daten["Pack".$i."_Zelle9"];
            $query .= ",Zelle10=".$daten["Pack".$i."_Zelle10"];
            $query .= ",Zelle11=".$daten["Pack".$i."_Zelle11"];
            $query .= ",Zelle12=".$daten["Pack".$i."_Zelle12"];
            $query .= ",Zelle13=".$daten["Pack".$i."_Zelle13"];
            $query .= ",Zelle14=".$daten["Pack".$i."_Zelle14"];
            $query .= ",Zelle15=".$daten["Pack".$i."_Zelle15"];
            $query .= ",Temp_Anz=".$daten["Pack".$i."_Temp_Anz"];
            $query .= ",Temp1=".$daten["Pack".$i."_Temp1"];
            $query .= ",Temp2=".$daten["Pack".$i."_Temp2"];
            $query .= ",Temp3=".$daten["Pack".$i."_Temp3"];
            $query .= ",Temp4=".$daten["Pack".$i."_Temp4"];
            $query .= ",Temp5=".$daten["Pack".$i."_Temp5"];
            $query .= ",Strom=".$daten["Pack".$i."_Strom"];
            $query .= ",Spannung=".$daten["Pack".$i."_Spannung"];
            $query .= ",Ah_left=".$daten["Pack".$i."_Ah_left"];
            $query .= ",Ah_total=".$daten["Pack".$i."_Ah_total"];
            if ($daten["Regler"] == 41) {
              $query .= ",Ah_left_2=".$daten["Pack".$i."_Ah_left_2"];
              $query .= ",Ah_total_2=".$daten["Pack".$i."_Ah_total_2"];
            }
            $query .= ",Cycle=".$daten["Pack".$i."_cycle"];
            $query .= "\n";

            $query .= "Warnungen_Pack".$i." ";
            $query .= "Zelle1=".$daten["Pack".$i."_Warn_Zelle1"];
            $query .= ",Zelle2=".$daten["Pack".$i."_Warn_Zelle2"];
            $query .= ",Zelle3=".$daten["Pack".$i."_Warn_Zelle3"];
            $query .= ",Zelle4=".$daten["Pack".$i."_Warn_Zelle4"];
            $query .= ",Zelle5=".$daten["Pack".$i."_Warn_Zelle5"];
            $query .= ",Zelle6=".$daten["Pack".$i."_Warn_Zelle6"];
            $query .= ",Zelle7=".$daten["Pack".$i."_Warn_Zelle7"];
            $query .= ",Zelle8=".$daten["Pack".$i."_Warn_Zelle8"];
            $query .= ",Zelle9=".$daten["Pack".$i."_Warn_Zelle9"];
            $query .= ",Zelle10=".$daten["Pack".$i."_Warn_Zelle10"];
            $query .= ",Zelle11=".$daten["Pack".$i."_Warn_Zelle11"];
            $query .= ",Zelle12=".$daten["Pack".$i."_Warn_Zelle12"];
            $query .= ",Zelle13=".$daten["Pack".$i."_Warn_Zelle13"];
            $query .= ",Zelle14=".$daten["Pack".$i."_Warn_Zelle14"];
            $query .= ",Zelle15=".$daten["Pack".$i."_Warn_Zelle15"];
            $query .= ",Temp1=".$daten["Pack".$i."_Warn_Temp1"];
            $query .= ",Temp2=".$daten["Pack".$i."_Warn_Temp2"];
            $query .= ",Temp3=".$daten["Pack".$i."_Warn_Temp3"];
            $query .= ",Temp4=".$daten["Pack".$i."_Warn_Temp4"];
            $query .= ",Temp5=".$daten["Pack".$i."_Warn_Temp5"];
            $query .= ",Ladestrom=".$daten["Pack".$i."_Warn_LadeStrom"];
            $query .= ",Spannung=".$daten["Pack".$i."_Warn_Spannung"];
            $query .= ",Entladestrom=".$daten["Pack".$i."_Warn_Entladestrom"];
            $query .= ",Status1=".$daten["Pack".$i."_Warn_Status1"];
            $query .= ",Status2=".$daten["Pack".$i."_Warn_Status2"];
            $query .= ",Status3=".$daten["Pack".$i."_Warn_Status3"];
            $query .= ",Status4=".$daten["Pack".$i."_Warn_Status4"];
            $query .= ",Status5=".$daten["Pack".$i."_Warn_Status5"];
            $query .= "\n";
          }

          $query .= "Service ";
          $query .= "Anzahl_Packs=".$daten["Packs"];
          for ($i = 1 ; $i <= $daten["Packs"]; $i++) {
            $query .= ",Pack".$i."_Status=".$daten["Pack".$i."_Status"];
          }
          $Summe = 0;
          for ($i = 1; $i <= $daten["Packs"]; $i++) {
            $Summe = $Summe + $daten["Pack".$i."_Strom"];
          }
          $query .= ",Be_Entladung=".$Summe;
          // Summe Ampere/h alle Batterien
          $Summe = 0;
          for ($i = 1 ; $i <= $daten["Packs"]; $i++) {
	    if ($daten["Regler"] == 41) {
              $Summe = $Summe + $daten["Pack".$i."_Ah_left_2"];
            }
	    else {
              $Summe = $Summe + $daten["Pack".$i."_Ah_left"];
            }
          }
          $query .= ",Restkapazitaet_Gesamt=".$Summe;
          // SOC alle Batterien
          //SOC berechnen us3000 74Ah - us2000 50Ah
          if ($daten["Regler"] == 41) {
            $query .= ",SOC=".$Summe/((74*$daten["Packs"])/100);
          }
          else {
            $query .= ",SOC=".$Summe/((50*$daten["Packs"])/100);
          }
          $Summe = 0;
          $query .= "\n";
          break;

        //  SolarEdge
        case 16:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          if (isset($daten["Batterie1Modell"])) {
            $query .= "Batterie_1 ";
            $query .= "Temperatur=".$daten["Batterie1Temp"];
            $query .= ",Spannung=".$daten["Batterie1Spannung"];
            $query .= ",Strom=".$daten["Batterie1Strom"];
            $query .= ",Leistung=".$daten["Batterie1Leistung"];
            $query .= ",SOH=".$daten["Batterie1StatusSOH"];
            $query .= ",SOE=".$daten["Batterie1StatusSOE"];
            $query .= ",Status=".$daten["Batterie1Status"];
            $query .= "\n";										// *
          }
          if (isset($daten["Batterie2Modell"])) {
            $query .= "Batterie_2 ";
            $query .= "Temperatur=".$daten["Batterie2Temp"];
            $query .= ",Spannung=".$daten["Batterie2Spannung"];
            $query .= ",Strom=".$daten["Batterie2Strom"];
            $query .= ",Leistung=".$daten["Batterie2Leistung"];
            $query .= ",SOH=".$daten["Batterie2StatusSOH"];
            $query .= ",SOE=".$daten["Batterie2StatusSOE"];
            $query .= ",Status=".$daten["Batterie2Status"];
            $query .= "\n";										// *
          }
          $query .= "AC ";
          $query .= "Spannung=".$daten["M1_AC_Spannung"];
          $query .= ",Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Wirkungsgrad=".$daten["AC_Leistung_Prozent"];
          $query .= ",Verbrauch=".$daten["AC_Verbrauch"];
          $query .= ",Bezug=".$daten["AC_Bezug"];
          $query .= ",Einspeisung=".$daten["AC_Einspeisung"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["DC_Spannung"];
          $query .= ",Leistung=".$daten["DC_Leistung"];
          $query .= ",Strom=".$daten["DC_Strom"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",Status=".$daten["Status"];
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["AC_Wh_Gesamt"];
          $query .= ",Wh_Gesamt_Export=".$daten["M1_AC_Exportgesamt_Wh"];
          $query .= ",Wh_Gesamt_Import=".$daten["M1_AC_Importgesamt_Wh"];
          break;

        // KOSTAL Plenticore Serie
        case 17:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          if ( $daten["AnzahlPhasen"] == 3) {
            $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
            $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          }
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Ausgangslast=".$daten["Ausgangslast"];
          $query .= ",Verbrauch=".$daten["Verbrauch"];
          $query .= ",Einspeisung=".$daten["Einspeisung"];
          $query .= ",Ueberschuss=".$daten["Ueberschuss"];
          $query .= ",Solarleistung=".$daten["AC_Solarleistung"];
          $query .= ",Verbrauch_Netz=".$daten["Verbrauch_Netz"];
          $query .= ",Verbrauch_Batterie=".$daten["Verbrauch_Batterie"];
          $query .= ",Verbrauch_PV=".$daten["Verbrauch_PV"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Strom=".$daten["Batteriestrom"];
          $query .= ",SOC=".$daten["SOC"];
          $query .= ",Temperatur=".$daten["Batterie_Temperatur"];
          $query .= ",Anzahl_Zyklen=".$daten["Bat_Cycles"];
          $query .= ",Lade_Entladeleistung=".$daten["Bat_Charge_Power"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Gesamtleistung=".$daten["PV_Leistung"];
          $query .= ",Spannung_Str_1=".$daten["PV1_Spannung"];
          $query .= ",Leistung_Str_1=".$daten["PV1_Leistung"];
          $query .= ",Strom_Str_1=".$daten["PV1_Strom"];
          if ( $daten["AnzahlStrings"] > 1) {
            $query .= ",Spannung_Str_2=".$daten["PV2_Spannung"];
            $query .= ",Strom_Str_2=".$daten["PV2_Strom"];
            $query .= ",Leistung_Str_2=".$daten["PV2_Leistung"];
          }
          if ( $daten["AnzahlStrings"] > 2) {
            $query .= ",Spannung_Str_3=".$daten["PV3_Spannung"];
            $query .= ",Strom_Str_3=".$daten["PV3_Strom"];
            $query .= ",Leistung_Str_3=".$daten["PV3_Leistung"];
          }

          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Status=".$daten["Status"];
          $query .= ",Temp_WR_Cab=".$daten["Temp_WR_Cab"];
          $query .= ",Temp_WR_Sink=".$daten["Temp_WR_Sink"];
          $query .= ",Temp_WR_Trans=".$daten["Temp_WR_Trans"];
          $query .= ",Seriennummer=\"".$daten["Seriennummer"]."\"";
          $query .= ",DC_Gesamtleistung=".$daten["Total_DC_Power"];
          $query .= ",Laufzeit=".$daten["Laufzeit"];
          $query .= ",WirkungsgradWR=".$daten["WirkungsgradWR"];
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],2);
          $query .= ",Wh_Gesamt_Monat=".$daten["WattstundenGesamtMonat"];
          $query .= ",Wh_Gesamt_Jahr=".$daten["WattstundenGesamtJahr"];
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Wh_Gesamtverbrauch_Netz=".$daten["Gesamtverbrauch_Netz"];
          $query .= ",Wh_Gesamtverbrauch_PV=".$daten["Gesamtverbrauch_PV"];
          $query .= ",Wh_Gesamtverbrauch_Batterie=".$daten["Gesamtverbrauch_Batterie"];
          break;


        // Wechselrichter S10E nd S10 mini von E3/DC
        case 18:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Verbrauch=".$daten["AC_Verbrauch"];
          $query .= ",Bezug=".$daten["AC_Bezug"];
          $query .= ",Solarleistung=".$daten["PV_Leistung"];
          $query .= ",AC_Wallbox_Leistung=".$daten["AC_Leistung_Wallbox"];
          $query .= ",AutarkieProzent=".$daten["Autarkie"];
          $query .= ",VerbrauchProzent=".$daten["Verbrauch"];
          $query .= ",Leistungsmesser=".$daten["Leistungsmesser"];
          $query .= ",Leistung_L1_in_Watt=".$daten["Phasenleistung_L1"];
          $query .= ",Leistung_L2_in_Watt=".$daten["Phasenleistung_L2"];
          $query .= ",Leistung_L3_in_Watt=".$daten["Phasenleistung_L3"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "SOC=".$daten["SOC"];
          $query .= ",Leistung=".$daten["Batterie_Leistung"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Gesamtleistung=".$daten["PV_Leistung"];
          $query .= ",String1_Spannung=".$daten["DC_String1_Spannung"];
          $query .= ",String1_Strom=".$daten["DC_String1_Strom"];
          $query .= ",String1_Leistung=".$daten["DC_String1_Leistung"];
          $query .= ",String2_Spannung=".$daten["DC_String2_Spannung"];
          $query .= ",String2_Strom=".$daten["DC_String2_Strom"];
          $query .= ",String2_Leistung=".$daten["DC_String2_Leistung"];
          $query .= ",String3_Spannung=".$daten["DC_String3_Spannung"];
          $query .= ",String3_Strom=".$daten["DC_String3_Strom"];
          $query .= ",String3_Leistung=".$daten["DC_String3_Leistung"];
          $query .= ",PV_Wallbox_Leistung=".$daten["PV_Leistung_Wallbox"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Modell=\"".$daten["Modell"]."\"";
          $query .= ",Seriennummer=\"".$daten["Seriennummer"]."\"";
          $query .= ",PowerStatus=".$daten["Power_Status"];
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],2);
          break;

        case 19:                       // eSmart3 Laderegler
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".substr($daten["Firmware"],1);
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Ladestrom=".$daten["Batterieladestrom"];
          $query .= ",Entladestrom=".$daten["Verbraucherstrom"];
          $query .= ",Entladeleistung=".$daten["Verbraucherleistung"];
          $query .= ",Temperatur=".$daten["Batterietemperatur"];
          $query .= ",SOC=".$daten["SOC"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",Fehler=".$daten["ErrorCode"];
          $query .= ",Ladestatus=".$daten["Ladestatus"];
          $query .= ",Auslastung=".$daten["Auslastung"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Wh_GesamtMonat=".$daten["WattstundenGesamtMonat"];
          $query .= ",Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;

        //  SolarEdge ohne MODBUS Zähler
        case 20:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
          $query .= ",Wirkungsgrad=".$daten["AC_Leistung_Prozent"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["DC_Spannung"];
          $query .= ",Leistung=".$daten["DC_Leistung"];
          $query .= ",Strom=".$daten["DC_Strom"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",Status=".$daten["Status"];
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["AC_Wh_Gesamt"];
          break;

        //  Kostal Pico
        case 21:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Strom_R=".$daten["AC_Strom_R"];
          $query .= ",Strom_S=".$daten["AC_Strom_S"];
          $query .= ",Strom_T=".$daten["AC_Strom_T"];
          $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
          $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
          $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "String1_Spannung=".$daten["PV_Spannung_1"];
          $query .= ",String2_Spannung=".$daten["PV_Spannung_2"];
          $query .= ",String3_Spannung=".$daten["PV_Spannung_3"];
          $query .= ",String1_Strom=".$daten["PV_Strom_1"];
          $query .= ",String2_Strom=".$daten["PV_Strom_2"];
          $query .= ",String3_Strom=".$daten["PV_Strom_3"];
          $query .= ",String1_Leistung=".$daten["PV_Leistung_1"];
          $query .= ",String2_Leistung=".$daten["PV_Leistung_2"];
          $query .= ",String3_Leistung=".$daten["PV_Leistung_3"];
          $query .= ",String1_Nummer=\"1\"";
          $query .= ",String2_Nummer=\"2\"";
          $query .= ",String3_Nummer=\"3\"";
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "FehlerCode=".$daten["FehlerCode"];
          $query .= ",Status=".$daten["Status"];
          $query .= ",Fehler=".$daten["Fehler"];
          $query .= ",Strings=".$daten["Strings"];
          $query .= ",Phasen=".$daten["Phasen"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["AC_Wh_Gesamt"];
          $query .= ",AC_Leistung_Gesamt=".$daten["AC_Leistung"];
          $query .= ",PV_Leistung_Gesamt=".$daten["PV_Leistung"];
          break;

        //  KOSTAL Smart Energy Meter
        case 22:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["Voltage_L1"];
          $query .= ",Spannung_S=".$daten["Voltage_L2"];
          $query .= ",Spannung_T=".$daten["Voltage_L3"];
          $query .= ",Strom_R=".$daten["Current_L1"];
          $query .= ",Strom_S=".$daten["Current_L2"];
          $query .= ",Strom_T=".$daten["Current_L3"];
          $query .= ",Leistung_pos=".$daten["Active_powerP"];
          $query .= ",Blindleistung_pos=".$daten["Reactive_powerP"];
          $query .= ",Scheinleistung_pos=".$daten["Apparent_powerP"];
          $query .= ",Leistung_neg=".$daten["Active_powerM"];
          $query .= ",Blindleistung_neg=".$daten["Reactive_powerM"];
          $query .= ",Scheinleistung_neg=".$daten["Apparent_powerM"];
          $query .= ",Frequenz=".$daten["Frequency"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt_Leistung_pos=".$daten["Active_energyP"];
          $query .= ",Wh_Gesamt_Blindleistung_pos=".$daten["Reactive_energyP"];
          $query .= ",Wh_Gesamt_Scheinleistung_pos=".$daten["Apparent_energyP"];
          $query .= ",Wh_Gesamt_Leistung_neg=".$daten["Active_energyM"];
          $query .= ",Wh_Gesamt_Blindleistung_neg=".$daten["Reactive_energyM"];
          $query .= ",Wh_Gesamt_Scheinleistung_neg=".$daten["Apparent_energyM"];
         break;

        // SONOFF POW R2, TH16 R2 und Gosund SP1
        case 23:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          // --------------------------
          if ($daten["SonoffModul"] == 43 or $daten["SonoffModul"] == 55) { // POW R2 + Gosung SP1   
            $query .= "AC ";
            $query .= "Spannung=".$daten["AC_Spannung"];
            $query .= ",Strom=".$daten["AC_Strom"];
            $query .= ",Leistung=".$daten["AC_Leistung"];
            $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
            $query .= ",Blindleistung=".$daten["AC_Blindleistung"];
            $query .= "\n";
          }
          elseif ($daten["SonoffModul"] == 200) { // Shelly 2.5
            $query .= "AC ";
            $query .= "Spannung=".$daten["AC_Spannung"];
            $query .= ",Strom1=".$daten["AC_Strom0"];
            $query .= ",Leistung1=".$daten["AC_Leistung0"];
            $query .= ",Scheinleistung1=".$daten["AC_Scheinleistung0"];
            $query .= ",Blindleistung1=".$daten["AC_Blindleistung0"];
            $query .= ",Strom2=".$daten["AC_Strom1"];
            $query .= ",Leistung2=".$daten["AC_Leistung1"];
            $query .= ",Scheinleistung2=".$daten["AC_Scheinleistung1"];
            $query .= ",Blindleistung2=".$daten["AC_Blindleistung1"];
            $query .= ",Frequenz=".$daten["AC_Frequenz"];
            $query .= "\n";
          }
          // --------------------------
          $query .= "Service ";
          $query .= "Status=\"".$daten["Status"]."\"";
          if ($daten["SonoffModul"] == 4) {         //TH16 R2
            $query .= ",Temperatur=".$daten["Temperatur"];
            $query .= ",Masseinheit=\"".$daten["Masseinheit"]."\"";
            $query .= ",Luftfeuchte=".$daten["Luftfeuchte"];
          }
          if ($daten["SonoffModul"] == 200) { // Shelly 2.5
            $query .= ",Temperatur=".$daten["Temperatur"];
            $query .= ",Powerstatus1=".$daten["Powerstatus0"];
            $query .= ",Powerstatus2=".$daten["Powerstatus1"];
          }
          else {
            $query .= ",Powerstatus=".$daten["Powerstatus"];
          }
          $query .= "\n";
          // --------------------------
          if ($daten["SonoffModul"] == 43 or $daten["SonoffModul"] == 55 or $daten["SonoffModul"] == 200) {
            $query .= "Summen ";
            $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
            $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          }
          break;

    // Infini 3KW Hybrid Wechselrichter
        case 24:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung=".$daten["Netzspannung"];
          $query .= ",Strom=".$daten["Netzstrom"];
          $query .= ",Leistung=".$daten["Netzleistung"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Leistung=".$daten["AC_Ausgangsleistung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Ausgangslast=".$daten["AC_Ausgangslast"];
          $query .= ",Stromrichtung=".$daten["Bezug_Einspeisung"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
          $query .= ",Stromrichtung=".$daten["Laden_Entladen"];				// *
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung1=".$daten["Solarspannung1"];
          $query .= ",Spannung2=".$daten["Solarspannung2"];
          $query .= ",Spannung3=".$daten["Solarspannung3"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",Leistung1=".$daten["Solarleistung1"];
          $query .= ",Leistung2=".$daten["Solarleistung2"];
          $query .= ",Leistung3=".$daten["Solarleistung3"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Modus=\"".$daten["Modus"]."\"";
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= ",Status=".$daten["DeviceStatus"];
          $query .= ",Fehlermeldung=\"".$daten["Fehlermeldung"]."\"";			// *
          $query .= ",Warnungen=\"".$daten["Warnungen"]."\"";				// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],2);
          $query .= ",kWh_Total=".$daten["KiloWattstundenTotal"];
          break;

        case 25:                       // Sonnen Batterie
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Netzladung=".$daten["Batterie_Netzladung"];
          $query .= ",Batterieladung=".$daten["Batterieladung"];
          $query .= ",PV_Ladung=".$daten["Batterie_PV_Ladung"];
          $query .= ",User_SOC=".$daten["User_SOC"];
          $query .= ",SOC=".$daten["SOC"];
          $query .= ",Entladung=".$daten["Batterieentladung"];
          $query .= ",Leistung=".$daten["Lade_Entladeleistung"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Erzeugung=".$daten["PV_Erzeugung"];
          $query .= ",Leistung=".$daten["PV_Leistung"];
          $query .= ",Einspeisung=".$daten["PV_Einspeisung"];
          $query .= "\n";
          $query .= "AC ";
          $query .= "Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["Ausgangsleistung"];
          $query .= ",Einspeisung=".$daten["AC_Einspeisung"];
          $query .= ",Bezug=".$daten["AC_Bezug"];
          $query .= ",Spannung=".$daten["AC_Spannung"];
          $query .= ",Verbrauch=".$daten["Verbrauch"];
          $query .= ",Einspeisung_Bezug=".$daten["Einspeisung_Bezug"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Systemstatus=\"".$daten["Systemstatus"]."\"";
          $query .= ",Operating_Mode=".$daten["Operating_Mode"];
          $query .= ",Netzbezug=".$daten["Netzbezug"];
          $query .= ",PV_Einspeisung=".$daten["PV_Einspeisung"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          break;

        // SMA Geräte
        case 27:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Softwarepaket"];
            $query .= ",Produkt=\"".$daten["Geraeteklasse"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";								// *
          }
          if (isset($daten["Modell"]) and $daten["Modell"] == "Island") {
            $query .= "Batterie ";
            $query .= "Temperatur=".$daten["Temperatur"];
            $query .= ",Spannung=".$daten["Batteriespannung"];
            $query .= ",Strom=".$daten["Batteriestrom"];
            $query .= ",Batterieladung=".$daten["Batterieladung"];
            $query .= ",SOC=".$daten["SOC"];
            $query .= ",SOE=".$daten["SOE"];
            $query .= ",Batteriestatus=".$daten["Batteriestatus"];
            $query .= "\n";										// *
          }
          $query .= "AC ";
          $query .= "Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          if (isset($daten["Modell"]) and $daten["Modell"] == "Tripower") {
            $query .= ",Spannung_R=".$daten["AC_Spannung_R"];
            $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
            $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          }
          $query .= ",Bezug=".$daten["AC_Leistung_Bezug"];
          $query .= ",Einspeisung=".$daten["AC_Leistung_Einspeisung"];
          $query .= "\n";										// *
          if (isset($daten["Modell"]) and $daten["Modell"] == "Tripower") {
            $query .= "PV ";
            $query .= "Leistung=".$daten["DC_Leistung"];
            $query .= ",Spannung1=".$daten["DC_Spannung1"];
            $query .= ",Leistung1=".$daten["DC_Leistung1"];
            $query .= ",Strom1=".$daten["DC_Strom1"];
            $query .= ",Spannung2=".$daten["DC_Spannung2"];
            $query .= ",Leistung2=".$daten["DC_Leistung2"];
            $query .= ",Strom2=".$daten["DC_Strom2"];
            $query .= ",Spannung3=".$daten["DC_Spannung3"];
            $query .= ",Leistung3=".$daten["DC_Leistung3"];
            $query .= ",Strom3=".$daten["DC_Strom3"];
            $query .= ",Spannung4=".$daten["DC_Spannung4"];
            $query .= ",Leistung4=".$daten["DC_Leistung4"];
            $query .= ",Strom4=".$daten["DC_Strom4"];
            $query .= ",Spannung5=".$daten["DC_Spannung5"];
            $query .= ",Leistung5=".$daten["DC_Leistung5"];
            $query .= ",Strom5=".$daten["DC_Strom5"];
            $query .= ",Spannung6=".$daten["DC_Spannung6"];
            $query .= ",Leistung6=".$daten["DC_Leistung6"];
            $query .= ",Strom6=".$daten["DC_Strom6"];
            $query .= "\n";										// *
          }
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",LiveBit=".$daten["LiveBit"];
          $query .= ",Status=".$daten["Geraetestatus"];
          $query .= ",Betriebszustand=".$daten["Betriebszustand"];
          $query .= "\n";
          if (isset($daten["Modell"]) and $daten["Modell"] == "Tripower") {             							        // *
            $query .= "Summen ";
            $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
            $query .= ",Wh_Gesamt=".$daten["Wh_Gesamt"];
            $query .= ",Wh_Gesamt_Export=".$daten["Einspeisung_Wh"];
            $query .= ",Wh_Gesamt_Import=".$daten["Bezug_Wh"];
          }
          break;

        case 28:                       // HDRi marlec Laderegler
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
            $query .= "\n";
          }
          $query .= "Batterie1 ";
          $query .= "Spannung=".$daten["Batteriespannung1"];
          $query .= "\n";
          $query .= "Batterie2 ";
          $query .= "Spannung=".$daten["Batteriespannung2"];
          $query .= "\n";
          $query .= "DC ";
          $query .= "WG_Strom=".$daten["WGStrom"];
          $query .= ",PV_Strom=".$daten["PVStrom"];
          $query .= ",WG_PV_Strom=".$daten["NetStrom"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "OperatingMode1=".$daten["OperatingMode1"];
          $query .= ",OperatingMode2=".$daten["OperatingMode2"];
          $query .= ",OperatingMode3=".$daten["OperatingMode3"];
          $query .= ",Betriebsstunden=".$daten["Betriebsstunden"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "WG_Ah=".$daten["WGAh"];
          $query .= ",PV_Ah=".$daten["PVAh"];
          $query .= ",WG_PV_Ah=".$daten["NetAh"];
          $query .= ",Wh_Heute=".$daten["WattstundenGesamtHeute"];
          $query .= "\n";

          break; 


    // go-e Charger Wallbox
        case 29:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["nrg"][0];
          $query .= ",Spannung_S=".$daten["nrg"][1];
          $query .= ",Spannung_T=".$daten["nrg"][2];
          $query .= ",Strom_R=".($daten["nrg"][4]/10);
          $query .= ",Strom_S=".($daten["nrg"][5]/10);
          $query .= ",Strom_T=".($daten["nrg"][6]/10);
          $query .= ",Leistung_R=".($daten["nrg"][7]*100);
          $query .= ",Leistung_S=".($daten["nrg"][8]*100);
          $query .= ",Leistung_T=".($daten["nrg"][9]*100);
          $query .= ",Leistung_gesamt=".($daten["nrg"][11]*10);
          $query .= ",Leistungsfaktor_R=".$daten["nrg"][12];
          $query .= ",Leistungsfaktor_S=".$daten["nrg"][13];
          $query .= ",Leistungsfaktor_T=".$daten["nrg"][14];
          $query .= ",Leistungsfaktor_N=".$daten["nrg"][15];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Temperatur=".$daten["tmp"];
          $query .= ",Stationsstatus=".$daten["car"];
          $query .= ",MaxAmpere=".$daten["amp"];
          $query .= ",ErrorCode=".$daten["err"];
          $query .= ",Zugangskontrolle=".$daten["ast"];
          $query .= ",Abschaltung=".$daten["stp"];
          $query .= ",RFID_Karte=".$daten["uby"];
          $query .= ",Karteninhaber=\"".$daten["Karteninhaber"]."\"";
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".($daten["eto"]*100);
          $query .= ",Wh_Ladevorgang=".round(($daten["dws"]*10/3600),0);
          $query .= ",Wh_Karte1=".($daten["eca"]*100);
          $query .= ",Wh_Karte2=".($daten["ecr"]*100);
          $query .= ",Wh_Karte3=".($daten["ecd"]*100);
          $query .= ",Wh_Karte4=".($daten["ec4"]*100);
          $query .= ",Wh_Karte5=".($daten["ec5"]*100);
          $query .= ",Wh_Karte6=".($daten["ec6"]*100);
          $query .= ",Wh_Karte7=".($daten["ec7"]*100);
          $query .= ",Wh_Karte8=".($daten["ec8"]*100);
          $query .= ",Wh_Karte9=".($daten["ec9"]*100);
          $query .= ",Wh_Karte10=".($daten["ec1"]*100);
          $query .= "\n";
          break;

    // Keba Wallbox
        case 30:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["U1"];
          $query .= ",Spannung_S=".$daten["U2"];
          $query .= ",Spannung_T=".$daten["U3"];
          $query .= ",Strom_R=".($daten["I1"]/1000);
          $query .= ",Strom_S=".($daten["I2"]/1000);
          $query .= ",Strom_T=".($daten["I3"]/1000);
          $query .= ",Leistung_gesamt=".($daten["P"]/1000);
          $query .= ",Leistungsfaktor=".($daten["PF"]/10);
          $query .= "\n";
          $query .= "Service ";
          $query .= "Stationsstatus=".$daten["State"];
          $query .= ",MaxAmpere=".($daten["Max curr"]/1000);
          $query .= ",MaxAmpereHardware=".($daten["Curr HW"]/1000);
          $query .= ",MaxAmpereUser=".($daten["Curr user"]/1000);
          $query .= ",ErrorCode1=".$daten["Error1"];
          $query .= ",ErrorCode2=".$daten["Error2"];
          $query .= ",Ladekabel=".$daten["Plug"];
          $query .= ",EnableSys=".$daten["Enable sys"];
          $query .= ",EnableUser=".$daten["Enable user"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".($daten["E total"]/10);
          $query .= ",Wh_Ladevorgang=".round(($daten["E pres"]/10),0);
          $query .= "\n";
          break;

    // Shelly 3EM
        case 31:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["Spannung_R"];
          $query .= ",Spannung_S=".$daten["Spannung_S"];
          $query .= ",Spannung_T=".$daten["Spannung_T"];
          $query .= ",Strom_R=".$daten["Strom_R"];
          $query .= ",Strom_S=".$daten["Strom_S"];
          $query .= ",Strom_T=".$daten["Strom_T"];
          $query .= ",Leistung_VerbrauchGesamt_R=".$daten["Wh_VerbrauchGesamt_R"];
          $query .= ",Leistung_VerbrauchGesamt_S=".$daten["Wh_VerbrauchGesamt_S"];
          $query .= ",Leistung_VerbrauchGesamt_T=".$daten["Wh_VerbrauchGesamt_T"];
          $query .= ",Leistung_EinspeisungGesamt_R=".$daten["Wh_EinspeisungGesamt_R"];
          $query .= ",Leistung_EinspeisungGesamt_S=".$daten["Wh_EinspeisungGesamt_S"];
          $query .= ",Leistung_EinspeisungGesamt_T=".$daten["Wh_EinspeisungGesamt_T"];
          $query .= ",PowerFactor_R=".$daten["PowerFactor_R"];
          $query .= ",PowerFactor_S=".$daten["PowerFactor_S"];
          $query .= ",PowerFactor_T=".$daten["PowerFactor_T"];
          $query .= ",Wirkleistung_R=".$daten["Wirkleistung_R"];
          $query .= ",Wirkleistung_S=".$daten["Wirkleistung_S"];
          $query .= ",Wirkleistung_T=".$daten["Wirkleistung_T"];
          $query .= ",Gesamtleistung=".$daten["LeistungGesamt"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Relaisstatus=".$daten["Relaisstatus"];
          $query .= ",Ueberlastung=".$daten["Ueberlastung"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".$daten["WattstundenGesamtHeute"];
          $query .= ",Wh_Gesamt_Verbrauch=".$daten["WattstundenGesamt_Verbrauch"];
          $query .= ",Wh_Gesamt_Einspeisung=".$daten["WattstundenGesamt_Einspeisung"];
          $query .= "\n";
          break;

        //  KACO Wechselrichter TL3 Serie
        case 32:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Ausgangsspannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Ausgangsspannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Ausgangsspannung_T"];
          $query .= ",Strom_R=".$daten["AC_Ausgangsstrom_R"];
          $query .= ",Strom_S=".$daten["AC_Ausgangsstrom_S"];
          $query .= ",Strom_T=".$daten["AC_Ausgangsstrom_T"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Scheinleistung=".$daten["AC_Scheinleistung"];
          $query .= ",Blindleistung=".$daten["AC_Blindleistung"];
          $query .= ",Wirkungsgrad=".$daten["AC_Leistungsfaktor"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Leistung=".$daten["Solarleistung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",Status=".$daten["Status"];
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          break;

        //  Labornetzteil JT-8600
        case 33:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "DC ";
          $query .= "Spannung=".$daten["DC_Volt"];
          $query .= ",Strom=".$daten["DC_Ampere"];
          $query .= ",SetSpannung=".$daten["DC_setVolt"];
          $query .= ",SetStrom=".$daten["DC_setAmpere"];
          $query .= ",MaxSpannung=".$daten["DC_maxVolt"];
          $query .= ",MaxStrom=".$daten["DC_maxAmpere"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",Status=".$daten["Geraetestatus"];
          $query .= ",Konstante=".$daten["DC_Konstante"];
          $query .= "\n";										        // *
          break;


        //  SDM630 Energy Meter
        case 34:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Strom_R=".$daten["AC_Strom_R"];
          $query .= ",Strom_S=".$daten["AC_Strom_S"];
          $query .= ",Strom_T=".$daten["AC_Strom_T"];
          $query .= ",Frequenz=".$daten["Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Leistungsfaktor=".$daten["PF_Leistung"];
          $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
          $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
          $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
          $query .= ",Leistungsfaktor_R=".$daten["PF_R"];
          $query .= ",Leistungsfaktor_S=".$daten["PF_S"];
          $query .= ",Leistungsfaktor_T=".$daten["PF_T"];
          $query .= ",Spannung=".$daten["AC_Spannung"];
          $query .= ",Strom=".$daten["AC_Strom"];
          $query .= "\n";										// *
          $query .= "Summen ";
          $query .= "Wh_GesamtBezug=".round($daten["Wh_Bezug"],1);
          $query .= ",Wh_GesamtEinspeisung=".$daten["Wh_Einspeisung"];
          $query .= ",GesamtLeistungsbedarf=".$daten["GesamterLeistungsbedarf"];
          break;


    // Wallbe Wallbox  und  Phoenix Contact Wallbox
        case 35:
        case 47:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["Spannung_R"];
          $query .= ",Spannung_S=".$daten["Spannung_S"];
          $query .= ",Spannung_T=".$daten["Spannung_T"];
          $query .= ",Strom_R=".$daten["Strom_R"];
          $query .= ",Strom_S=".$daten["Strom_S"];
          $query .= ",Strom_T=".$daten["Strom_T"];
          $query .= ",Leistung_gesamt=".$daten["Leistung"];
          $query .= ",Frequenz=".$daten["Frequenz"];
          $query .= ",Ladestrom=".$daten["Ladestrom"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Stationsstatus=\"".$daten["Status"]."\"";
          $query .= ",StatusHex=".bin2hex($daten["Status"]);
          $query .= ",ErrorCode=".$daten["ErrorCode"];
          $query .= ",LadungAktiv=".$daten["LadungAktiv"];
          $query .= ",LadestationEin=".$daten["LadestationEin"];
          $query .= ",PerModbusAktiviert=".$daten["PerModbusAktiviert"];
          $query .= ",Ladung_erlaubt=".$daten["Ladung_erlaubt"];
          $query .= ",Kabel_angeschlossen=".$daten["Kabel_angeschlossen"];
          $query .= ",Kabel_entriegelt=".$daten["Kabel_entriegeln"];
          $query .= ",MaxLadestrom=".$daten["MaxLadestrom"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".$daten["GesamtLeistung"];
          $query .= ",Wh_Ladevorgang=".$daten["LadeLeistung"];
          $query .= "\n";
          break;


        //  Delta Wechselrichter SL 2500  [ Variant 1 ]
        case 36:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Spannung"];
          $query .= ",Strom=".$daten["AC_Strom"];
          $query .= ",Frequenz=".$daten["Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Leistung=".($daten["Solarspannung"]*$daten["Solarstrom"]);
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur_DC=".$daten["DC_Temperatur"];
          $query .= ",Temperatur_AC=".$daten["AC_Temperatur"];
          $query .= ",Modell=\"".$daten["Modell"]."\"";
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          $query .= ",Betriebsstunden=".$daten["Betriebsstunden"];
          break;


    // Simple EVSE Wallbox
        case 37:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Strom_R=".($daten["Strom_R"]);
          $query .= ",Strom_S=".($daten["Strom_S"]);
          $query .= ",Strom_T=".($daten["Strom_T"]);
          $query .= ",Leistung=".($daten["aktuelleLeistung"]);
          $query .= ",Stromvorgabe=".($daten["aktuelleStromvorgabe"]);
          $query .= "\n";
          $query .= "Service ";
          $query .= "Stationsstatus=".$daten["Stationsstatus"];
          $query .= ",MaxAmpere=".($daten["MaxAmpere"]);
          $query .= ",freigeschaltet=".$daten["freigeschaltet"];
          $query .= ",Ladedauer=".$daten["Ladedauer"];
          $query .= ",immerAktiv=".$daten["immerAktiv"];
          $query .= ",User=\"".$daten["letzterUser"]."\"";
          $query .= ",UID=\"".$daten["letzteUID"]."\"";
          $query .= ",Km_Ladevorgang=".$daten["Km_Ladevorgang"];
          $query .= ",Stromvorgabe=".($daten["aktuelleStromvorgabe"]);
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".($daten["Wh_Meter"]*1000);
          $query .= ",Wh_Ladevorgang=".$daten["Wh_Ladevorgang"];
          $query .= "\n";
          break;





    // ALPHA ESS Wechselrichter
        case 38:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query   .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          if (isset($daten["Alpha_ESS"]) and $daten["Alpha_ESS"] == 1) {
            // Neue Datenbank Struktur
            $query .= "Netz ";
            $query .= "Leistung_R=".$daten["AC_Wirkleistung_R"];
            $query .= ",Leistung_S=".$daten["AC_Wirkleistung_S"];
            $query .= ",Leistung_T=".$daten["AC_Wirkleistung_T"];
            $query .= ",Gesamtleistung=".$daten["AC_Wirkleistung"];
            $query .= ",EinspeisungGesamt=".$daten["NetzeinspeisungGesamt"];
            $query .= ",BezugGesamt=".$daten["NetzbezugGesamt"];
            $query .= ",PVAC_Leistung_R=".$daten["PV_Wirkleistung_R"];
            $query .= ",PVAC_Leistung_S=".$daten["PV_Wirkleistung_S"];
            $query .= ",PVAC_Leistung_T=".$daten["PV_Wirkleistung_T"];
            $query .= ",PVAC_Gesamtleistung=".$daten["AC_LeistungGesamt"];
            $query .= ",PVAC_PV-ErzeugungGesamt=".$daten["PV_EinspeisungGesamt"];
            $query .= "\n";										// *
            $query .= "Inverter ";
            $query .= "Spannung_R=".$daten["AC_Spannung_R"];
            $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
            $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
            $query .= ",Strom_R=".$daten["AC_Strom_R"];
            $query .= ",Strom_S=".$daten["AC_Strom_S"];
            $query .= ",Strom_T=".$daten["AC_Strom_T"];
            $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
            $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
            $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
            $query .= ",Frequenz=".$daten["BackupFrequenz"];
            $query .= ",Temperatur=".$daten["Temperatur"];
            $query .= ",Fehler=\"".$daten["WR_Fehler"]."\"";			// *
            $query .= ",Warnungen=\"".$daten["WR_Warnungen"]."\"";
            $query .= ",PV_Spannung1=".$daten["PV_Spannung1"];
            $query .= ",PV_Spannung2=".$daten["PV_Spannung2"];
            $query .= ",PV_Spannung3=".$daten["PV_Spannung3"];
            $query .= ",PV_Strom1=".$daten["PV_Strom1"];
            $query .= ",PV_Strom2=".$daten["PV_Strom2"];
            $query .= ",PV_Strom3=".$daten["PV_Strom3"];
            $query .= ",PV_Leistung=".$daten["PV_Leistung"];
            $query .= ",PV_Leistung1=".$daten["PV_Leistung1"];
            $query .= ",PV_Leistung2=".$daten["PV_Leistung2"];
            $query .= ",PV_Leistung3=".$daten["PV_Leistung3"];
            $query .= ",Total_PV_Energy=".$daten["PV_LeistungGesamt"];
            $query .= ",Backup_Spannung_R=".$daten["AC_BackupSpannung_R"];
            $query .= ",Backup_Spannung_S=".$daten["AC_BackupSpannung_S"];
            $query .= ",Backup_Spannung_T=".$daten["AC_BackupSpannung_T"];
            $query .= ",Backup_Leistung_R=".$daten["AC_BackupLeistung_R"];
            $query .= ",Backup_Leistung_S=".$daten["AC_BackupLeistung_S"];
            $query .= ",Backup_Leistung_T=".$daten["AC_BackupLeistung_T"];
            $query .= ",Backup_Strom_R=".$daten["AC_BackupStrom_R"];
            $query .= ",Backup_Strom_S=".$daten["AC_BackupStrom_S"];
            $query .= ",Backup_Strom_T=".$daten["AC_BackupStrom_T"];
            $query .= ",Backup_LeistungGesamt=".$daten["AC_BackupLeistung"];
            $query .= "\n";										// *
            $query .= "Batterie ";
            $query .= "Spannung=".$daten["Batteriespannung"];
            $query .= ",Strom=".$daten["Batteriestrom"];				// *
            $query .= ",SOC=".$daten["SOC"];				// *
            $query .= ",Batteriestatus=\"".$daten["Batteriestatus"]."\"";
            $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
            $query .= ",Fehler=\"".$daten["Batteriefehler"]."\"";			// *
            $query .= ",Warnungen=\"".$daten["Batteriewarnungen"]."\"";				// *
            $query .= ",Ladeleistung=".$daten["BatterieladeenergieGesamt"];
            $query .= ",Entladeleistung=".$daten["BatterieentladeenergieGesamt"];
            $query .= ",Netzladung=".$daten["Netzladeenergie"];
            $query .= "\n";										// *
            $query .= "System ";
            $query .= "Einspeisung_prozentual=".$daten["Netzeinspeisung"];
            $query .= "\n";										        // *
          }
          else {
            //  Alte Datenbank Struktur
            $query .= "Netz ";
            $query .= "Spannung_R=".$daten["AC_Spannung_R"];
            $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
            $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
            $query .= ",Strom_R=".$daten["AC_Strom_R"];
            $query .= ",Strom_S=".$daten["AC_Strom_S"];
            $query .= ",Strom_T=".$daten["AC_Strom_T"];
            $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
            $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
            $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
            $query .= ",Leistung=".$daten["AC_Leistung"];
            $query .= ",Wirkleistung=".$daten["AC_Wirkleistung"];
            $query .= ",Frequenz=".$daten["BackupFrequenz"];
            $query .= "\n";										// *
            $query .= "AC ";
            $query .= "Spannung_R=".$daten["AC_BackupSpannung_R"];
            $query .= ",Spannung_S=".$daten["AC_BackupSpannung_S"];
            $query .= ",Spannung_T=".$daten["AC_BackupSpannung_T"];
            $query .= ",Frequenz=".$daten["BackupFrequenz"];
            $query .= ",Leistung_R=".$daten["AC_BackupLeistung_R"];
            $query .= ",Leistung_S=".$daten["AC_BackupLeistung_S"];
            $query .= ",Leistung_T=".$daten["AC_BackupLeistung_T"];
            $query .= ",Strom_R=".$daten["AC_BackupStrom_R"];
            $query .= ",Strom_S=".$daten["AC_BackupStrom_S"];
            $query .= ",Strom_T=".$daten["AC_BackupStrom_T"];
            $query .= ",Leistung=".$daten["AC_BackupLeistung"];
            $query .= ",PV_AC_Wirkleistung_R=".$daten["PV_Wirkleistung_R"];
            $query .= ",PV_AC_Wirkleistung_S=".$daten["PV_Wirkleistung_S"];
            $query .= ",PV_AC_Wirkleistung_T=".$daten["PV_Wirkleistung_T"];
            $query .= ",PV_AC_Total_Active_Power=".$daten["PV_LeistungGesamt"];
            $query .= "\n";										// *
            $query .= "Batterie ";
            $query .= "Spannung=".$daten["Batteriespannung"];
            $query .= ",Kapazitaet=".$daten["Batteriekapazitaet"];
            $query .= ",Strom=".$daten["Batteriestrom"];				// *
            $query .= ",SOC=".$daten["SOC"];				// *
            $query .= "\n";										// *
            $query .= "PV ";
            $query .= "Spannung1=".$daten["PV_Spannung1"];
            $query .= ",Spannung2=".$daten["PV_Spannung2"];
            $query .= ",Spannung3=".$daten["PV_Spannung3"];
            $query .= ",Strom1=".$daten["PV_Strom1"];
            $query .= ",Strom2=".$daten["PV_Strom2"];
            $query .= ",Strom3=".$daten["PV_Strom3"];
            $query .= ",Leistung=".$daten["PV_Leistung"];
            $query .= ",Leistung1=".$daten["PV_Leistung1"];
            $query .= ",Leistung2=".$daten["PV_Leistung2"];
            $query .= ",Leistung3=".$daten["PV_Leistung3"];
            $query .= "\n";										// *
            $query .= "Service ";
            $query .= "Batteriestatus=\"".$daten["Batteriestatus"]."\"";
            $query .= ",Temperatur=".$daten["Temperatur"];
            $query .= ",WR_Fehler=\"".$daten["WR_Fehler"]."\"";			// *
            $query .= ",WR_Warnungen=\"".$daten["WR_Warnungen"]."\"";				// *
            $query .= ",Bat_Fehler=\"".$daten["Batteriefehler"]."\"";			// *
            $query .= ",Bat_Warnungen=\"".$daten["Batteriewarnungen"]."\"";				// *
            $query .= "\n";										        // *
            $query .= "Summen ";
            $query .= "Wh_NetzeinspeisungGesamt=".$daten["NetzeinspeisungGesamt"];
            $query .= ",Wh_NetzbezugGesamt=".$daten["NetzbezugGesamt"];
            $query .= ",PV_GesamtHeute=".$daten["WattstundenGesamtHeute"];
            $query .= ",PV_AC_Total_Energy_feed_to_Grid=".$daten["PV_EinspeisungGesamt"];
          }
          break;

      // openWB Wallbox
        case 39:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["VPhase1"];
          $query .= ",Spannung_S=".$daten["VPhase2"];
          $query .= ",Spannung_T=".$daten["VPhase3"];
          $query .= ",Strom_R=".$daten["APhase1"];
          $query .= ",Strom_S=".$daten["APhase2"];
          $query .= ",Strom_T=".$daten["APhase3"];
          $query .= ",Leistung_gesamt=".$daten["W"];
          $query .= ",aktuelleLeistung=".$daten["W"];
          $query .= ",Hausverbrauch=".$daten["WHouseConsumption"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Stationsstatus=".$daten["Stationsstatus"];
          $query .= ",MaxAmpere=".$daten["ADirectModeAmps"];
          $query .= ",LadestationName=\"".$daten["strChargePointName"]."\"";
          $query .= ",LadeStecker=".$daten["boolPlugStat"];
          $query .= ",Ladevorgang=".$daten["boolChargeStat"];
          $query .= ",Ladestatus=".$daten["Ladestatus"];
          $query .= ",SOC=".$daten["%Soc"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".($daten["eto"]*100);
          $query .= ",Wh_Ladeenergie=".($daten["kWhActualCharged"]*1000);
          $query .= ",Wh_Ladevorgang=".($daten["kWhChargedSincePlugged"]*1000);
          $query .= "\n";
          break;

    // Phocos PH1800 Wechselrichter , PV18 VHM Wechselrichter
        case 40:
        case 42:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Maschinentype"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung=".$daten["Netzspannung"];
          $query .= ",Strom=".$daten["Netzstrom"];
          $query .= ",Leistung=".$daten["Netzleistung"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Leistung=".$daten["AC_Ausgangsleistung"];
          $query .= ",Strom=".$daten["AC_Ausgangsstrom"];
          $query .= ",Ausgangslast=".$daten["AC_Ausgangslast"];
          if ($daten["Regler"] == 42) {
            $query .= ",Last=".$daten["Last_Prozent"];
          }
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Soll_Ah=".$daten["Batterie_Ah"];
          $query .= ",Leistung=".$daten["Batterieleistung"];
          $query .= ",Ladereglerleistung=".$daten["Ladereglerleistung"];
          $query .= ",Ladereglerstrom=".$daten["Ladereglerstrom"];
          $query .= ",Relais=".$daten["Bat_Relay"];				// *
          $query .= ",Strom=".$daten["Batterie_Strom"];				// *
          $query .= ",SpannungAmInverter=".$daten["Bat_Spannung"];				// *
          $query .= ",Max_Ampere=".$daten["Max_Ampere"];			// *
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Relais=".$daten["PV_Relay"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "ReglerMode=".$daten["Regler_Mode"];
          $query .= ",Geraetestatus=".$daten["WR_Status"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          if ($daten["Regler"] == 42) {
            $query .= ",AC_Temperatur=".$daten["AC_Temperatur"];
          }
          $query .= ",MPPTStatus=".$daten["MPPT_Status"];
          $query .= ",ReglerStatus=".$daten["Regler_Status"];
          $query .= ",BatterieRelais=".$daten["Bat_Relay"];
          $query .= ",PVRelais=".$daten["PV_Relay"];
          $query .= ",Fehler=".$daten["Fehler"];			// *
          $query .= ",Warnungen=".$daten["Warnungen"];				// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".$daten["Wh_Gesamt"];
          $query .= ",Wh_GesamtHeute=".round($daten["WattstundenGesamtHeute"],2);
          break;


    // Senec Geräte
        case 43:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Strom_R=".$daten["AC_Strom_R"];
          $query .= ",Strom_S=".$daten["AC_Strom_S"];
          $query .= ",Strom_T=".$daten["AC_Strom_T"];
          $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
          $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
          $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Frequenz=".$daten["Frequenz"];
          $query .= ",Hausverbrauch=".$daten["Hausverbrauch"];
          $query .= ",Einspeisung_Bezug=".$daten["Netz_Leistung"];
          $query .= ",Einspeisung=".$daten["Einspeisung"];
          $query .= ",Bezug=".$daten["Bezug"];
          $query .= ",Frequenz=".$daten["Frequenz"];
          $query .= ",Eingangsleistung=".$daten["PV_Leistung"];
          $query .= "\n";
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Bat_Spannung"];
          $query .= ",SOC=".$daten["Bat_SOC"];
          $query .= ",Leistung=".$daten["Bat_Leistung"];
          $query .= ",Anzahl=".$daten["Anz_Batterien"];
          $query .= "\n";
          $query .= "PV ";
          $query .= "Begrenzung=".$daten["PV_Begrenzung"];
          $query .= ",Leistung=".$daten["PV_Leistung"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Status=".$daten["Status"];
          $query .= ",Betriebsstunden=".$daten["Betriebsstunden"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_NetzeinspeisungGesamt=".$daten["EinspeisungGesamt"];
          $query .= ",Wh_NetzbezugGesamt=".$daten["NetzbezugGesamt"];
          $query .= ",Wh_PVLeistungGesamt=".$daten["PV_Gesamtleistung"];
          $query .= ",Wh_GesamtHeute=".round($daten["WattstundenGesamtHeute"],2);
          $query .= "\n";
          break;

    // Webasto Wallbox
        case 44:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Strom_R=".$daten["Strom_R"];
          $query .= ",Strom_S=".$daten["Strom_S"];
          $query .= ",Strom_T=".$daten["Strom_T"];
          $query .= ",Leistung=".$daten["Leistung"];
          $query .= ",Ladestrom=".$daten["AktuelleStromvorgabe"];
          $query .= "\n";
          $query .= "Service ";
          $query .= "Stationsstatus=\"".$daten["Ladestatus"]."\"";
          $query .= ",ErrorCode=".$daten["ErrorCode"];
          $query .= ",LadungAktiv=".$daten["LadungAktiv"];
          $query .= ",EVSEStatus=".$daten["EVSEStatus"];
          $query .= ",Kabelstatus=".$daten["Kabelstatus"];
          $query .= ",KabelLimitAmp=".$daten["KabelLimit"];
          $query .= ",HardwareLimitAmp=".$daten["HardwareLimit"];
          $query .= ",MinLadestrom=".$daten["MinLadestrom"];
          $query .= ",MaxAmpere=".$daten["AktuelleStromvorgabe"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Gesamt=".$daten["GesamtLeistung"];
          $query .= ",Wh_Ladevorgang=".$daten["LadeLeistung"];
          $query .= "\n";
          break;


    // Phocos Any-Grid
        case 45:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query .= ",Produkt=\"".$daten["Maschinentype"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "Netz ";
          $query .= "Spannung=".$daten["Netzspannung"];
          $query .= ",Frequenz=".$daten["Netzfrequenz"];
          $query .= "\n";										// *
          $query .= "AC ";
          $query .= "Spannung=".$daten["AC_Ausgangsspannung"];
          $query .= ",Frequenz=".$daten["AC_Ausgangsfrequenz"];
          $query .= ",Leistung=".$daten["AC_Ausgangsleistung"];
          $query .= ",Ausgangslast=".$daten["AC_Ausgangslast"];
          $query .= "\n";										// *
          $query .= "Batterie ";
          $query .= "Spannung=".$daten["Batteriespannung"];
          $query .= ",Strom=".$daten["Batterie_Strom"];				// *
          $query .= ",Max_Ampere=".$daten["Max_Ampere"];			// *
          $query .= ",SOC=".$daten["SOC"];			// *
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung=".$daten["Solarspannung"];
          $query .= ",Strom=".$daten["Solarstrom"];
          $query .= ",Leistung=".$daten["PV_Leistung"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "ReglerMode=".$daten["Regler_Mode"];
          $query .= ",Geraetestatus=\"".$daten["Modus"]."\"";
          $query .= ",Fehler=".$daten["Fehler"];			// *
          $query .= ",Priority=".$daten["DeviceStatus"];			// *
          $query .= ",OutputMode=".$daten["Mode"];			// *
          $query .= ",InverterStatus=\"".$daten["Status"]."\"";			// *
          $query .= ",IntModus=".$daten["IntModus"];			// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_GesamtHeute=".round($daten["WattstundenGesamtHeute"],2);
          break;


    // Huawei Wechselrichter
        case 46:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=".$daten["Firmware"];
            $query   .= ",Produkt=\"".$daten["Produkt"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Strom_R=".$daten["AC_Strom_R"];
          $query .= ",Strom_S=".$daten["AC_Strom_S"];
          $query .= ",Strom_T=".$daten["AC_Strom_T"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= ",Powerfactor=".$daten["AC_Powerfactor"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "Spannung1=".$daten["PV_Spannung1"];
          $query .= ",Spannung2=".$daten["PV_Spannung2"];
          $query .= ",Spannung3=".$daten["PV_Spannung3"];
          $query .= ",Strom1=".$daten["PV_Strom1"];
          $query .= ",Strom2=".$daten["PV_Strom2"];
          $query .= ",Strom3=".$daten["PV_Strom3"];
          $query .= ",Spannung4=".$daten["PV_Spannung4"];
          $query .= ",Spannung5=".$daten["PV_Spannung5"];
          $query .= ",Spannung6=".$daten["PV_Spannung6"];
          $query .= ",Strom4=".$daten["PV_Strom4"];
          $query .= ",Strom5=".$daten["PV_Strom5"];
          $query .= ",Strom6=".$daten["PV_Strom6"];
          $query .= ",Spannung7=".$daten["PV_Spannung7"];
          $query .= ",Spannung8=".$daten["PV_Spannung8"];
          $query .= ",Strom7=".$daten["PV_Strom7"];
          $query .= ",Strom8=".$daten["PV_Strom8"];
          $query .= ",Leistung=".$daten["PV_Leistung"];
          $query .= ",MPPT1_Leistung=".$daten["MPPT1_Leistung"];
          $query .= ",MPPT2_Leistung=".$daten["MPPT2_Leistung"];
          $query .= ",MPPT3_Leistung=".$daten["MPPT3_Leistung"];
          $query .= ",MPPT4_Leistung=".$daten["MPPT4_Leistung"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "Temperatur=".$daten["Temperatur"];
          $query .= ",WR_Fehler=\"".$daten["State1"]."\"";			// *
          $query .= ",Modell=".$daten["Modell"];				// *
          $query .= ",Status=".$daten["Status"];			// *
          $query .= ",Effizienz=\"".$daten["Effizienz"]."\"";				// *
          $query .= "\n";										        // *
          $query .= "Summen ";
          $query .= "Wh_GesamtHeute=".$daten["WattstundenGesamtHeute"];
          $query .= ",Wh_GesamtMonat=".$daten["WattstundenGesamtMonat"];
          $query .= ",Wh_GesamtJahr=".$daten["WattstundenGesamtJahr"];
          break;


        //  Growatt Wechselrichter
        case 48:
          if (date("i") == "01" or $daten["Demodaten"] or date("H") == date("H",$Sonnenaufgang)) {
            $query .= "Info ";
            $query .= "Firmware=\"".$daten["Firmware"]."\"";
            $query .= ",Produkt=\"".$daten["Modell"]."\"";
            $query .= ",Objekt=\"".$daten["Objekt"]."\"";
            $query .= ",Datum=\"".$daten["Datum"]."\"";
          }
          $query .= "\n";								// *
          $query .= "AC ";
          $query .= "Spannung_R=".$daten["AC_Spannung_R"];
          $query .= ",Spannung_S=".$daten["AC_Spannung_S"];
          $query .= ",Spannung_T=".$daten["AC_Spannung_T"];
          $query .= ",Strom_R=".$daten["AC_Strom_R"];
          $query .= ",Strom_S=".$daten["AC_Strom_S"];
          $query .= ",Strom_T=".$daten["AC_Strom_T"];
          $query .= ",Leistung_R=".$daten["AC_Leistung_R"];
          $query .= ",Leistung_S=".$daten["AC_Leistung_S"];
          $query .= ",Leistung_T=".$daten["AC_Leistung_T"];
          $query .= ",Frequenz=".$daten["AC_Frequenz"];
          $query .= ",Leistung=".$daten["AC_Leistung"];
          $query .= "\n";										// *
          $query .= "PV ";
          $query .= "String1_Spannung=".$daten["PV_Spannung1"];
          $query .= ",String2_Spannung=".$daten["PV_Spannung2"];
          $query .= ",String1_Strom=".$daten["PV_Strom1"];
          $query .= ",String2_Strom=".$daten["PV_Strom2"];
          $query .= ",String1_Leistung=".$daten["PV_Leistung1"];
          $query .= ",String2_Leistung=".$daten["PV_Leistung2"];
          $query .= ",Leistung=".$daten["PV_Leistung"];
          $query .= "\n";										// *
          $query .= "Service ";
          $query .= "FehlerCode=".$daten["FehlerCode"];
          $query .= ",Warnungen=".$daten["Warnungen"];
          $query .= ",Status=".$daten["Status"];
          $query .= ",AnzahlStrings=".$daten["Anz.MPPT"];
          $query .= ",AnzahlPhasen=".$daten["Anz.Phasen"];
          $query .= ",Temperatur=".$daten["Temperatur"];
          $query .= "\n";
          $query .= "Summen ";
          $query .= "Wh_Heute=".round($daten["WattstundenGesamtHeute"],1);
          $query .= ",Wh_Gesamt=".$daten["WattstundenGesamt"];
          break;


        default:
          $query = "";
          break;
      }


    return $query;
  }



  /**************************************************************************
  //  MQTT Fuktionen zum senden der mqtt Messages
  //  Call back functions for MQTT library
  ***************************************************************/
  function mqtt_connect($r) {
    global $MQTTDaten;

    $MQTTDaten["MQTTConncetReturnCode"] = $r;

    if($r == 0) $MQTTDaten["MQTTConnectReturnText"] = "{$r}-CONX-OK|";
    if($r == 1) $MQTTDaten["MQTTConnectReturnText"] = "{$r}-Connection refused (unacceptable protocol version)|";
    if($r == 2) $MQTTDaten["MQTTConnectReturnText"] = "{$r}-Connection refused (identifier rejected)|";
    if($r == 3) $MQTTDaten["MQTTConnectReturnText"] = "{$r}-Connection refused (broker unavailable )|";
    return;
  }

  function mqtt_publish() {
    global $MQTTDaten;
    $MQTTDaten["MQTTPublishReturnText"] =  "Message published";
  }

  function mqtt_disconnect() {
    global $MQTTDaten;
    $MQTTDaten["MQTTDisconnectReturnText"] = "Disconnected";
  }


  function mqtt_subscribe() {
    global $MQTTDaten;
    //**Store the status to a global variable - debug purposes
    // $GLOBALS['statusmsg'] = $GLOBALS['statusmsg'] . "SUB-OK|";
    $MQTTDaten["MQTTSubscribeReturnText"] = "SUB-OK|";
  }

  function mqtt_message($message) {
    global $MQTTDaten;
    $MQTTDaten["MQTTRetain"] = 0;
    //**Store the status to a global variable - debug purposes
    // $GLOBALS['statusmsg']  = "RX-OK|";
    //**Store the received message to a global variable
    $MQTTDaten["MQTTMessageReturnText"] =  "RX-OK";
    $MQTTDaten["MQTTNachricht"] =  $message->payload;
    $MQTTDaten["MQTTTopic"] =  $message->topic;
    $MQTTDaten["MQTTQos"] =  $message->qos;
    $MQTTDaten["MQTTMid"] =  $message->mid;
    $MQTTDaten["MQTTRetain"] =  $message->retain;
  }

  /******************************************************************************
  //  Umwandlung der Binärdaten in lesbare Form
  ******************************************************************************/
  function solarxxl_daten($daten,$Times = false, $Negativ = false) {

    $DeviceID = substr($daten,0,2);
    $BefehlFunctionCode = substr($daten,2,2);
    $RegisterCount = substr($daten,4,2);
    if ($Negativ) {
      if ($RegisterCount == "01") {
        $Ergebnis = $this->hexdecs(substr($daten,6,2));
      }
      elseif ($RegisterCount == "02")  {
        $Ergebnis = $this->hexdecs(substr($daten,6,4));
      }
      else {
        return false;
      }
    }
    else {
      if ($RegisterCount == "01") {
        $Ergebnis = hexdec(substr($daten,6,2));
      }
      elseif ($RegisterCount == "02")  {
        $Ergebnis = hexdec(substr($daten,6,4));
      }
      else {
        return false;
      }
    }

    if ($Times == true) {
      $Ergebnis = $Ergebnis/100;
    }
    return $Ergebnis;
  }


  /****************************************************************************
  //  Hier wird der RS845 Bus ausgelesen. Diese Routine ist sehr zeitkritisch
  //  Bitte die usleep() Funktionen nicht verändern, zumindest erst nach
  //  längeren Testreihen.
  //  Bei schnelleren Raspberry Pi Rechnern müssen die zeitlichen Funktionen
  //  eventuell angepasst werden.
  //  $Input["DeviceID"]
  //  $Input["BefehlFunctionCode"]
  //  $Input["RegisterAddress"]
  //  $Input["RegisterCount"]
  ****************************************************************************/
  function tracer_auslesen($USB,$Input) {

    stream_set_blocking ( $USB , false );

    $BefehlBin = hex2bin($Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"]);

    $BefehlBin = $BefehlBin.$this->crc16($BefehlBin);

    // Befehl in HEX!
    // echo $Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"];
    $Laenge = strlen($BefehlBin);

    for ($k = 1; $k < 3; $k++) {
      $buffer = fgets($USB, 500);
      $buffer = "";
      fputs ($USB, $BefehlBin);
      usleep(50000);  // 0,05 Sekunden warten

      for ($i = 1; $i < 50; $i++) {

        $buffer .= fgets($USB, 100);
        usleep(10000);
        //echo $i." ".bin2hex($buffer)."\n";
        if (substr($buffer,0,$Laenge) == $BefehlBin) {
          // echo "Echo erhalten ".$i."\n";
          $buffer = substr($buffer,$Laenge);
          $buffer = "";
        }

        if (substr($buffer,0,2) == substr($BefehlBin,0,2)) {
          if (strlen($buffer) == 7 ) {
            if ( bin2hex($buffer) == "0140020000b930") {
              break;
            } 
            //echo "Ausgang: ".$i."\n";
            stream_set_blocking ( $USB , true );
            return bin2hex($buffer);
          }
          else {
            // echo "break1: ".bin2hex($buffer)."\n";
          }
        }
        elseif(strlen($buffer) > 0) {
          // echo "break2\n";
          break;
        }
      }

     }

    stream_set_blocking ( $USB , true );
    return false;
  }


  function  us2000_daten_entschluesseln($Daten) {
    $Ergebnis = array();

    $Ergebnis["Ver"] = substr($Daten,0,2);
    $Ergebnis["ADR"] = substr($Daten,2,2);
    $Ergebnis["CID1"] = substr($Daten,4,2);
    $Ergebnis["CID2"] = substr($Daten,6,2);
    $Ergebnis["LENHEX"] = substr($Daten,8,4);
    $Ergebnis["LEN"] = hexdec(substr($Daten,9,3));
    if ($Ergebnis["LEN"] > 0) {
      $Ergebnis["INFO"] = substr($Daten,12,$Ergebnis["LEN"]);
    }

    if ($Ergebnis["CID2"] <> 0) {
      $Ergebnis["Fehler"] = true;
    }
    else {
      $Ergebnis["Fehler"] = false;
    }
    return $Ergebnis;
  }

  /****************************************************************************
  //  Hier wird das Polytech US2000 Protokoll ausgelesen.
  //  Bei schnelleren Raspberry Pi Rechnern müssen die zeitlichen Funktionen
  //  eventuell angepasst werden.
  //
  ****************************************************************************/
  function us2000_auslesen($USB,$Input) {

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";
    $Dauer = 8; // Sekunden

    stream_set_blocking( $USB , false );

    if (strtolower($Input) <> "trace") {

      fputs ($USB,$Input);
      usleep(100000);  // 0,1 Sekunde
    }

    do {
      $Antwort .= fgets($USB,1024); // 4096

      if (substr($Antwort,0,1) == "~" and substr($Antwort,-1) == "\r") {
        // echo trim($Antwort);
        stream_set_blocking ( $USB , true );
        return trim(substr($Antwort,1));
      }

    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking ( $USB , true );

    return false;
  }





  /******************************************************************
  // 
  //  Auslesen des MPPSolar Wechselrichter      MPI
  //
  ******************************************************************/
  function mpi_usb_lesen($USB,$Input) {

    stream_set_blocking ( $USB , false );
    $Antwort = "";

    //  Die folgenden 2 Zeilen dienen der Syncronisation der Hidraw 
    //  Schnittstelle und sollten bestehen bleiben.
    fputs ($USB, "\r");
    fgets($USB,50); // 50


    //  Der Befehl wird gesendet...
    //  Die Geräte sind empfindlich beim Empfang
    fputs ($USB, substr($Input,0,5));
    usleep(10000);   // 10000
    if (strlen($Input) > 15) {
      fputs ($USB, substr($Input,5,5));
      usleep(10000);   // 10000
      fputs ($USB, substr($Input,10,5));
      usleep(10000);   // 10000
      fputs ($USB, substr($Input,15)."\r");
      usleep(10000);   // 10000
    }
    elseif (strlen($Input) > 10) {
      fputs ($USB, substr($Input,5,5));
      usleep(10000);   // 10000
      fputs ($USB, substr($Input,10)."\r");
      usleep(10000);   // 10000
    }
    else {
      fputs ($USB, substr($Input,5)."\r");
      usleep(10000);   // 10000
    }

    for ($k = 1 ; $k < 200; $k++) {
      $rc = fgets($USB,4096); // 4096
      usleep(30000);  // 30000
      $Antwort .= $rc;
      // echo bin2hex($rc)."\n";
      if (substr($Antwort,0,2)  == "^D" and strlen($Antwort) > 5) {
        if ((substr($Antwort,2,3)+5) <= strlen($Antwort)) {
          if (strlen($Antwort) > substr($Antwort,2,3)) {
            $Laenge = (substr($Antwort,2,3) -3);
            stream_set_blocking ( $USB , true );
            return substr($Antwort,5,$Laenge);
          }
        }
      }
      if (substr($Antwort,0,2)  == "^0") {
        // Befehl nicht erfolgreich
        break;
      }
      if (substr($Antwort,0,2)  == "^1") {
        stream_set_blocking ( $USB , true );
        // Befehl erfolgreich
        return "OK";
      }
  
    }

    stream_set_blocking ( $USB , true );
    // echo bin2hex($Antwort)."\n";

    return false;
  }


  /**********************************************************
  //   MPPSolar Daten entschlüsseln
  //
  **********************************************************/

  function mpi_entschluesseln($Befehl,$Daten) {

    $Ergebnis = array();

    switch ($Befehl) {

      case "GS":
       $Teile = explode(",",$Daten);
       $Ergebnis["Solarspannung1"] = ($Teile[0]/10);
       $Ergebnis["Solarspannung2"] = ($Teile[1]/10);
       $Ergebnis["Solarstrom1"] = ($Teile[2]/10);
       $Ergebnis["Solarstrom2"] = ($Teile[3]/10);
       $Ergebnis["Batteriespannung"] = ($Teile[4]/10);
       $Ergebnis["Batteriekapazitaet"] = $Teile[5];
       $Ergebnis["Batteriestrom"] = ($Teile[6]/10);
       $Ergebnis["Netzspannung_R"] = ($Teile[7]/10);
       $Ergebnis["Netzspannung_S"] = ($Teile[8]/10);
       $Ergebnis["Netzspannung_T"] = ($Teile[9]/10);
       $Ergebnis["Netzfrequenz"] = ($Teile[10]/100);
       $Ergebnis["Netzstrom_R"] = ($Teile[11]/10);
       $Ergebnis["Netzstrom_S"] = ($Teile[12]/10);
       $Ergebnis["Netzstrom_T"] = ($Teile[13]/10);
       $Ergebnis["AC_Ausgangsspannung_R"] = ($Teile[14]/10);
       $Ergebnis["AC_Ausgangsspannung_S"] = ($Teile[15]/10);
       $Ergebnis["AC_Ausgangsspannung_T"] = ($Teile[16]/10);
       $Ergebnis["AC_Ausgangsfrequenz"] = ($Teile[17]/100);
       if ($Teile[18] != null) {
         $Ergebnis["AC_Ausgangsstrom_R"] = ($Teile[18]/10);
         $Ergebnis["AC_Ausgangsstrom_S"] = ($Teile[19]/10);
         $Ergebnis["AC_Ausgangsstrom_T"] = ($Teile[20]/10);
       }
       else {
         $Ergebnis["AC_Ausgangsstrom_R"] = 0;
         $Ergebnis["AC_Ausgangsstrom_S"] = 0;
         $Ergebnis["AC_Ausgangsstrom_T"] = 0;
       }
       $Ergebnis["Temperatur"] = $Teile[21];
       $Ergebnis["Batterie_Temperatur"] = $Teile[23];
      break;

      case "PS":
       $Teile = explode(",",$Daten);
       $Ergebnis["Solarleistung1"] = round($Teile[0],0);
       $Ergebnis["Solarleistung2"] = round($Teile[1],0);
       $Ergebnis["Batterieleistung"] = $Teile[2];
       $Ergebnis["AC_Eingangsleistung_R"] = round($Teile[3],0);
       $Ergebnis["AC_Eingangsleistung_S"] = round($Teile[4],0);
       $Ergebnis["AC_Eingangsleistung_T"] = round($Teile[5],0);
       $Ergebnis["AC_Eingangsleistung"] = round($Teile[6],0);
       $Ergebnis["AC_Wirkleistung_R"] = round($Teile[7],0);
       $Ergebnis["AC_Wirkleistung_S"] = round($Teile[8],0);
       $Ergebnis["AC_Wirkleistung_T"] = round($Teile[9],0);
       $Ergebnis["AC_Wirkleistung"] = round($Teile[10],0);
       $Ergebnis["AC_Scheinleistung_R"] = round($Teile[11],0);
       $Ergebnis["AC_Scheinleistung_S"] = round($Teile[12],0);
       $Ergebnis["AC_Scheinleistung_T"] = round($Teile[13],0);
       $Ergebnis["AC_Scheinleistung"] = round($Teile[14],0);
       $Ergebnis["Ausgangslast"] = $Teile[15];          // in %
       $Ergebnis["AC_Ausgang_Status"] = $Teile[16];     // 0 = aus  1 = ein
       $Ergebnis["Solar_Status1"] = $Teile[17];         // 0 = aus  1 = ein
       $Ergebnis["Solar_Status2"] = $Teile[18];         // 0 = aus  1 = ein
       $Ergebnis["Batteriestromrichtung"] = $Teile[19]; // 0 = aus  1 = laden  2 = entladen
       $Ergebnis["WR_Stromrichtung"] = $Teile[20];      // 0 = aus  1 = AC/DC  2 = DC/AC
       $Ergebnis["Netzstromrichtung"] = $Teile[21];     // 0 = aus  1 = Eingang  2 = Ausgang
      break;

      case "CFS":
       $Teile = explode(",",$Daten);
       $Ergebnis["ErrorCodes"] = $Teile[0];
      break;

      case "PI":
       $Ergebnis["Firmware"] = $Daten;
      break;

      case "MOD":
       $Ergebnis["Modus"] = $Daten;
      break;
 
      case "ED".date("Ymd"):
       $Ergebnis["WattstundenGesamtHeute"] = $Daten;
      break;

      case "EM".date("Ym", strtotime("-1 month")):
        $Ergebnis["WattstundenGesamtMonat"] = $Daten;
      break;

      case "EY".date("Y", strtotime("-1 year")):
        $Ergebnis["WattstundenGesamtJahr"] = $Daten;
      break;

      case "ET":
        $Ergebnis["KiloWattstundenTotal"] = $Daten;
      break;

      case "DM":
        $Ergebnis["Produkt"] = $Daten;
      break;

      case "T":
       // Hier kann Datum und Uhrzeit entschlüsselt werden
       $Ergebnis["Stunden"] = substr($Daten,8,2);
       $Ergebnis["Minuten"] = substr($Daten,10,2);
       $Ergebnis["Sekunden"] = substr($Daten,12,2);
      break;

      case "WS":
       $Warnungen = array();
       $k = 1;
       $Ergebnis["Warnungen"] = 0;
       $Teile = explode(",",$Daten);
       for ($i = 0; $i < 22; $i++) {
         if ($Teile[$i] == 1) {
         // Es gibt eine oder mehrere Warnungen
           $Warnungen[$k] = ($i+1);
           $k++;
         }
       }
       $k = ($k-1);
       if ($k == 1) {
         $Ergebnis["Warnungen"] = $Warnungen[$k];
       }
       elseif ($k > 1){
         //  Es sind mehrere Warnungen vorhanden
         $Ergebnis["Warnungen"] = $Warnungen[rand(1,$k)];
       }
      break;
    }
    return $Ergebnis;
  }

  /******************************************************************
  //
  //  Auslesen des Fronius Symo Wechselrichter
  //
  ******************************************************************/

  function read($host,$port,$DataString) {


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5 );
    curl_setopt($ch, CURLOPT_TIMEOUT, 20 );
    curl_setopt($ch, CURLOPT_URL, "http://".$host."/".$DataString);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PORT, $port );

    //  In $result wird ein XML Dokument zurück gegeben!
    //  Dort steht drin ob die Zentrale den Wert übernommen hat.
    $result = curl_exec($ch);
    $rc_info = curl_getinfo ($ch);
    if ($rc_info["http_code"] == 404) {
      $this->log_schreiben("Datenabfrage falsch! info: ".var_export($rc_info,1),"   ",9);
      return false;
    }
    elseif ($rc_info["http_code"] != 200) {
      $this->log_schreiben("Datenabfrage falsch! info: ".var_export($rc_info,1),"   ",5);
      return false;
    }
    else {
      $this->log_schreiben("http://".$host."/".$DataString,"   ",10);
      $this->log_schreiben("Daten zum Gerät gesendet. \n Antwort: ".$result,"   ",8);
    }

    $Ausgabe = json_decode($result,true);
    return $Ausgabe;
  }


  function fronius_getFehlerString($error){
    $text ="";
    switch ($error) {
      // ---- 100 ----
      case 102:
        $text = "AC-Spannung zu hoch";
        break;
      case 103:
        $text = "AC-Spannung zu gering";
        break;
      case 105:
        $text = "AC-Frequenz zu hoch";
        break;
      case 106:
        $text = "AC-Frequenz zu gering";
        break;
      case 107:
        $text = "AC-Netz nicht vorhanden";
        break;
      case 108:
        $text = "Inselbetrieb erkannt";
        break;
      case 112:
        $text = "Fehler RCMU";
        break;
      case 301:
        $text = "Überstrom AC";
        break;
      case 302:
        $text = "Überstrom DC";
        break;
      case 303:
        $text = "Übertemperatur DC Modul";
        break;
      case 304:
        $text = "Übertemperatur AC Modul";
        break;
      case 305:
        $text = "Keine Einspeisung trotz geschlossener Relais";
        break;
      case 306:
        $text = "Zu wenig PV Leistung für Einspeisung";
        break;
      case 307:
        $text = "DC Spannung zu gering für Einspeisung";
        break;
      case 308:
        $text = "Zwischenkreisspannung zu hoch";
        break;
      case 309:
        $text = "DC Eingangsspannung MPPT1 zu hoch";
        break;
      case 311:
        $text = "DC Stränge verpolt";
        break;
      case 313:
        $text = "DC Eingangsspannung MPPT2 zu hoch";
        break;
      case 314:
        $text = "Timeout Stromsensor-Kalibrierung";
        break;
      case 315:
        $text = "AC Stromsensor Fehler";
        break;
      case 316:
        $text = "InterruptCheck falin";
        break;
      case 325:
       $text = "Übertemperatur im Anschlussbreich";
       break;
      case 326:
        $text = "Lüfter 1 Fehler";
        break;
      case 327:
        $text = "Lüfter 2 Fehler";
        break;
      case 401:
        $text = "Kommunikation mit dem Leistungsteil nicht möglich";
        break;
      case 406:
        $text = "Temperatursensor AC Modul defekt (L1)";
        break;
      case 407:
        $text = "Temperatursensor AC Modul defekt (L2)";
        break;
      case 408:
        $text = "Zu hoher Gleichanteil im Versorgungsnetz gemessen";
        break;
      case 412:
        $text = "Fixspannung statt MPP-Betrieb: Einstellwert ist zu niedrig oder zu hoch";
        break;
      case 415:
        $text = "Sicherheitsschaltung durch Optionskarte oder RECERBO hat ausgelöst";
        break;
      case 416:
        $text = "Kommunikation zwischen Leistungsteil und Steuerung nicht möglich";
        break;
      case 417:
        $text = "ID-Problem der Hardware";
        break;
      case 419:
        $text = "Unique-ID Konflikt";
        break;
      case 420:
        $text = "Kommunikation mit dem Hybridmanager nicht möglich";
        break;
      case 421:
        $text = "Fehler HID-Range";
        break;
      case 425:
        $text = "Kommunikation mit dem Leistungsteil ist nicht möglich";
        break;
      case 426:
        $text = "Möglicher Hardware-Defekt";
        break;
      case 427:
        $text = "Möglicher Hardware-Defekt";
        break;
      case 428:
        $text = "Möglicher Hardware-Defekt";
        break;
      case 431:
        $text = "Software Problem";
        break;
      case 436:
        $text = "Funktions-Inkompatibilität";
        break;
      case 437:
        $text = "Leistungsteil-Problem";
        break;
      case 438:
        $text = "Funktions-Inkompatibilität";
        break;
      case 443:
        $text = "Zwischenkreis-Spannung zu gering oder unsymetrisch";
        break;
      case 445:
        $text = "Kompatibilitätsfehler";
        break;
      case 447:
        $text = "Isolationsfehler";
        break;
      case 448:
        $text = "Neutralleiter nicht angeschlossen";
        break;
      case 450:
        $text = "Guard kann nicht gefunden werden";
        break;
      case 451:
        $text = "Speicherfehler entdeckt";
        break;
      case 452:
        $text = "Kommunikationsfehler zwischen den Prozessoren";
        break;
      case 453:
        $text = "Netzspannung und Leistungsteil stimmen nicht überein";
        break;
      case 454:
        $text = "Netzspannung und Leistungsteil stimmen nicht überein";
        break;
      case 456:
        $text = "Anti-Islanding-Funktion wird nicht mehr korrekt ausgeführt";
        break;
      case 457:
        $text = "Netzrelais klebt oder die Neutralleiter-Erde-Spannung ist zu hoch";
        break;
      case 458:
        $text = "Fehler bei der Mess-Signalerfassung";
        break;
      case 459:
        $text = "Fehler bei der Erfassung des Mess-Signals für den Isolationstest";
        break;
      case 460:
        $text = "Referenz-Spannungsquelle DSP außerhalb der tolerierten Grenzen";
        break;
     case 461:
        $text = "Fehler im DSP-Datenspeicher";
        break;
      case 462:
        $text = "Fehler bei der DC-Einspeisungs-Überwachungsroutine";
        break;
      case 463:
        $text = "Polarität AC vertauscht, AC-Verbindungsstecker falsch eingesteckt";
        break;
      case 474:
        $text = "RCMU-Sensor defekt";
        break;
      case 475:
        $text = "Isolationsfehler (Verbindung zwischen Solarmodul und Erdung)";
        break;
      case 476:
        $text = "Versorgungsspannung der Treiberversorgung zu gering";
        break;
      case 479:
        $text = "Zwischenkreis-Spannungsrelais hat ausgeschaltet";
        break;
      case 480:
        $text = "Funktions-Inkompatibilität";
        break;
      case 481:
        $text = "Funktions-Inkompatibilität";
        break;
      case 482:
        $text = "Setup nach der erstmaligen Inbetriebnahme wurde abgebrochen";
        break;
      case 483:
        $text = "Spannung UDCfix beim MPP2-String liegt außerhalb des gültigen Bereichs";
        break;
      case 485:
        $text = "CAN Sende-Buffer ist voll";
        break;
     case 489:
        $text = "Permanente Überspannung am Zwischenkreis-Kondensator";
        break;
      case 502:
        $text = "Isolationsfehler an den Solarmodulen";
        break;
      case 509:
        $text = "Keine Einspeisung innerhalb der letzten 24 Stunden";
        break;
      case 515:
        $text = "Kommunikation mit Filter nicht möglich";
        break;
      case 516:
        $text = "Kommunikation mit der Speichereinheit nicht möglich";
        break;
      case 517:
        $text = "Leistungs-Derating auf Grund zu hoher Temperatur";
        break;
      case 518:
        $text = "Interne DSP-Fehlfunktion";
        break;
      case 519:
        $text = "Kommunikation mit der Speichereinheit nicht möglich";
        break;
      case 520:
        $text = "Keine Einspeisung innerhalb der letzten 24 Stunden von MPPT1";
        break;
      case 522:
        $text = "DC low String 1";
        break;
      case 523:
        $text = "DC low String 2";
        break;
      case 558:
        $text = "Funktions-Inkompatibilität";
        break;
      case 559:
        $text = "Funktions-Inkompatibilität";
        break;
      case 560:
        $text = "Leistungs-Derating wegen Überfrequenz";
        break;
      case 564:
        $text = "Funktions-Inkompatibilität";
        break;
      case 566:
        $text = "Arc Detector ausgeschaltet (z.B. bei externer LichtbogenÜberwachung)";
        break;
      case 568:
        $text = "fehlerhaftes Eingangssignal an der Multifunktions-Stromschnittstelle";
        break;
      case 572:
        $text = "Leistungslimitierung durch das Leistungsteil";
         break;
      case 573:
        $text = "Untertemperatur Warnung";
        break;
      case 581:
        $text = "Setup ?Special Purpose Utility-Interactive? (SPUI) ist aktiviert";
        break;
      case 601:
        $text = "CAN Bus ist voll";
        break;
      case 603:
        $text = "Temperatursensor AC Modul defekt (L3)";
        break;
      case 604:
        $text = "Temperatursensor DC Modul defekt";
        break;
      case 607:
        $text = "RCMU Fehler";
        break;
      case 608:
        $text = "Funktions-Inkompatibilität";
        break;
      case 701:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
      case 702:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
      case 703:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
      break;
    case 704:
      $text = "Gibt Auskunft über den internen Prozessorstatus";
      break;
      // 706 - 716: 	$text = "Gibt Auskunft über den internen Prozessorstatus"
      case 721:
        $text = "EEPROM wurde neu initialisiert";
        break;
      // 722 - 730: 	$text = "Gibt Auskunft über den internen Prozessorstatus"
      case 731:
        $text = "Initialisierungsfehler - USB-Stick wird nicht unterstützt";
        break;
      case 732:
        $text = "Initialisierungsfehler - Überstrom am USB-Stick";
        break;
      case 733:
        $text = "Kein USB-Stick angesteckt";
        break;
      case 734:
        $text = "Update-Datei wird nicht erkannt oder ist nicht vorhanden";
        break;
      case 735:
        $text = "Nicht zum Gerät passende Update-Datei, zu alte Update-Datei";
        break;
      case 736:
        $text = "Schreib- oder Lesefehler aufgetreten";
        break;
      case 737:
        $text = "Datei konnte nicht geöffnet werden";
        break;
      case 738:
        $text = "Abspeichern einer Log-Datei nicht möglich";
        break;
      case 740:
        $text = "Initialisierungsfehler - Fehler im Dateisystem des USB-Sticks";
        break;
      case 741:
        $text = "Fehler beim Aufzeichnen von Logging-Daten";
        break;
      case 743:
        $text = "Fehler während des Updates aufgetreten";
        break;
      case 745:
        $text = "Update-Datei fehlerhaft";
        break;
      case 746:
        $text = "Fehler während des Updates aufgetreten";
        break;
      case 751:
        $text = "Uhrzeit verloren";
        break;
      case 752:
        $text = "Real Time Clock Modul Kommunikationsfehler";
        break;
      case 753:
        $text = "Interner Fehler: Real Time Clock Modul ist im Notmodus";
        break;
      case 754:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
      case 755:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
      case 757:
        $text = "Hardware-Fehler im Real Time Clock Modul";
        break;
      case 758:
        $text = "Interner Fehler: Real Time Clock Modul ist im Notmodus";
        break;
      case 760:
        $text = "Interner Hardware-Fehler";
        break;
        // 761 - 765: 	$text = "Gibt Auskunft über den internen Prozessorstatus"
      case 766:
        $text = "Notfall-Leistungsbegrenzung wurde aktiviert (max. 750 W)";
        break;
      case 767:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
      case 768:
        $text = "Leistungsbegrenzung in den Hardware-Modulen unterschiedlich";
        break;
      case 772:
        $text = "Speichereinheit nicht verfügbar";
        break;
      case 773:
        $text = "Software-Update Gruppe 0 (ungültiges Länder-Setup)";
        break;
      case 775:
        $text = "PMC-Leistungsteil nicht verfügbar";
        break;
      case 776:
        $text = "Device-Typ ungültig";
        break;
      // 781 - 794: 	$text = "Gibt Auskunft über den internen Prozessorstatus"
      default:
        $text = "Gibt Auskunft über den internen Prozessorstatus";
        break;
    }
    return $text;
  }


  /****************************************************************************
  //  Victron Geräte    Victron Geräte    Victron Geräte    Victron Geräte
  //  Hier wird das VE.Direct Protokoll ausgelesen.
  //  Bitte die usleep() Funktionen nicht verändern, zumindest erst nach
  //  längeren Testreihen.
  //  Bei schnelleren Raspberry Pi Rechnern müssen die zeitlichen Funktionen
  //  eventuell angepasst werden.
  //
  ****************************************************************************/
  function ve_regler_auslesen($USB,$Input) {

    stream_set_blocking( $USB , false );



    $rc = fgets($USB,128); // Alle Daten   die noch nicht entfernt sind hier entfernen.

    if (trim($rc) != "") {
      usleep(20000);
    }

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";
    $Laenge = (strlen($Input)-2);
    $Dauer = 2; // Sekunden

    //  Befehl senden...
    //  echo "Befehl: ".$Input." Länge: ".$Laenge."\n";
    fputs ($USB, $Input."\n");
    usleep(110000);       //  vorher 100000  23.1.2018

    do {

      $Antwort = fgets($USB,4096); // 4096


      if (trim($Antwort) == "") {
        //echo "Nichts empfangen .. \n";
        usleep(100); // vorher 500
        $Antwort = "";
        continue;
      }
      elseif (trim($Antwort) == $Input) {
        //echo "Echo empfangen .. \n";
        usleep(500);
        $Antwort = "";
      }
      elseif(substr($Antwort,0,2) == ":A") {
        continue;
      }
      else  {
        // Es kommen asynchrone Nachrichten. Pause machen!
        // echo date("s")." A: ".trim($Antwort)." ".strpos($Antwort,":")."\n";
        if (strpos($Antwort,":") === false) {
          continue;
        }
        elseif (substr($Antwort,0,2) == ":A") {
          continue;
        }
        else {
          // Das richtige Ergebnis
          $Ergebnis .= substr( $Antwort, strpos($Antwort,":"), strpos($Antwort,"\n"));
          
          if(substr($Antwort,0,2) == ":A") {
            $Ergebnis = "";
            continue;
          }
          //echo "E: ".$Ergebnis."\n";
          if(substr($Ergebnis,0,$Laenge) == substr($Input,0,$Laenge)) {
            break;
          }
          elseif (substr($Ergebnis,0,2) == ":1") {
            //  Antwort  Version
            break;
          }
          elseif (substr($Ergebnis,0,2) == ":4") {
            // Antwort  Device ID  oder Framefehler
            break;
          }
          elseif (substr($Ergebnis,0,2) == ":5") {
            //  Ping Antwort
            break;
          }
        }
      }

    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking ( $USB , true );

    return $Ergebnis;
  }


  /**************************************************************************
  //  Hier wird das VE.Direct Protokoll entschlüsselt.
  //
  **************************************************************************/
  function ve_ergebnis_auswerten($Daten) {

    $Ergebnis = array();

    $Response = substr($Daten,1,1);


    switch ($Response) {
      case 1:
        $Ergebnis["Produkt"] = substr($Daten,4,2).substr($Daten,2,2);
      break;

      case 4:
        $Ergebnis["Framefehler"] = substr($Daten,2,4);
      break;

      case 5:
        $Ergebnis["Firmware"] = substr($Daten,5,1).".".substr($Daten,2,2);
      break;

      case 6:
          $Ergebnis["Reboot"] = 1;
	  // Reboot des Reglers -> Keine Antwort
      break;

      case 7:
        if (substr($Daten,2,4) == "8DED") {      // Main Voltage
          $Ergebnis["Batteriespannung"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "FF0F") {    // SOC
          $Ergebnis["SOC"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "8FED") {     // Strom +/-
            $Ergebnis["Batteriestrom"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "8EED") {   // Power
            $Ergebnis["Leistung"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2)));
        }
        elseif (substr($Daten,2,4) == "FE0F") {
          $Ergebnis["TTG"] = hexdec(substr($Daten,10,2).substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "0503") {     // Cumulative Amp Hours
          $Ergebnis["AmperestundenGesamt"] = ($this->hexdecs(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "1003") {
          $Ergebnis["WattstundenGesamtEntladung"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))*10);
        }
        elseif (substr($Daten,2,4) == "1103") {
          $Ergebnis["WattstundenGesamtLadung"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))*10);
        }
        elseif (substr($Daten,2,4) == "ECED") {
          if (substr($Daten,8,4) == "FFFF") {
            $Ergebnis["Temperatur"] = 0;
          }
          else {
            $Ergebnis["Temperatur"] = $this->kelvinToCelsius(hexdec(substr($Daten,10,2).substr($Daten,8,2))/100);
          }
        }
        elseif (substr($Daten,2,4) == "0803") {    // Days since last full charge
          $Ergebnis["ZeitVollladung"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2)));
        }
        elseif (substr($Daten,2,4) == "FFEE") {
          $Ergebnis["Amperestunden"] = ($this->hexdecs(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "B6EE") {
          $Ergebnis["Ladestatus"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "0203") {
          $Ergebnis["EntladetiefeDurchschnitt"] = ($this->hexdecs(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "0303") {
          $Ergebnis["Ladezyklen"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2)));
        }
        elseif (substr($Daten,2,4) == "0403") {
          $Ergebnis["EntladungMax"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2)));
        }
        elseif (substr($Daten,2,4) == "0903") {
          $Ergebnis["AnzahlSynchronisationen"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2)));
        }
        elseif (substr($Daten,2,4) == "FCEE") {
          $Ergebnis["Alarm"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "4E03") {
          $Ergebnis["Relais"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "D5ED") {
          $Ergebnis["Batteriespannung"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "7DED") {      // Aux Voltage                                                                                                   
          $Ergebnis["BatteriespannungAux"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/100);                                                     
        }
        elseif (substr($Daten,2,4) == "D7ED") {
          //  Batterieladestrom
          $Ergebnis["Batterieladestrom"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "BBED") {
          $Ergebnis["Solarspannung"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "ADED") {
          $Ergebnis["Batterieentladestrom"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "4F10") {
          $Ergebnis["WattstundenGesamt"] = (hexdec(substr($Daten,26,2).substr($Daten,24,2).substr($Daten,22,2).substr($Daten,20,2))*10);
        }
        elseif (substr($Daten,2,4) == "DBED") {
          $Ergebnis["Temperatur"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "4001") {
          $Ergebnis["Optionen"] = decbin(hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2)));
          $Ergebnis["Optionen"] = str_pad ( $Ergebnis["Optionen"], 24, '0', STR_PAD_LEFT );
        }
        elseif (substr($Daten,2,4) == "0102") {
          $Ergebnis["Ladestatus"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "D2ED") {
          $Ergebnis["maxWattHeute"] = hexdec(substr($Daten,10,2).substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "BDED") {
          //  Solarstrom
          $Ergebnis["Solarstrom"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "A8ED") {
          //  Load Status
          $Ergebnis["LoadStatus"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "BCED") {
          $Ergebnis["Solarleistung"] = (hexdec(substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "5010") {
          $Ergebnis["WattstundenGesamtHeute"] = (hexdec(substr($Daten,16,2).substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2))*10);
          $Ergebnis["BulkMinutenHeute"] = hexdec(substr($Daten,46,2).substr($Daten,44,2));
          $Ergebnis["AbsorptionMinutenHeute"] = hexdec(substr($Daten,50,2).substr($Daten,48,2));
          $Ergebnis["FloatMinutenHeute"] = hexdec(substr($Daten,54,2).substr($Daten,52,2));
          $Ergebnis["BatterieladestromMaxHeute"] = (hexdec(substr($Daten,66,2).substr($Daten,64,2))/10);
          $Ergebnis["SolarspannungMaxHeute"] = (hexdec(substr($Daten,70,2).substr($Daten,68,2))/100);
        }
        elseif (substr($Daten,2,4) == "5110") {
          $Ergebnis["WattstundenGesamtGestern"] = (hexdec(substr($Daten,16,2).substr($Daten,14,2).substr($Daten,12,2).substr($Daten,10,2))*10);
        }
        elseif (substr($Daten,2,4) == "DAED") {
          $Ergebnis["ErrorCodes"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "DFED") {
          $Ergebnis["maxAmpHeute"] = (hexdec(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "0002") {
          //  Mode
          $Ergebnis["Mode"] = hexdec(substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "1C03") {
          //  Warnungen
          $Ergebnis["Warnungen"] = hexdec(substr($Daten,10,2).substr($Daten,8,2));
        }
        elseif (substr($Daten,2,4) == "0122") {
          //  AC_Strom  => wird negativ angegeben... deshalb abs()
          $Ergebnis["AC_Ausgangsstrom"] = abs($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/10);
        }
        elseif (substr($Daten,2,4) == "0022") {
          //  AC_Spannung
          $Ergebnis["AC_Ausgangsspannung"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/100);
        }
        elseif (substr($Daten,2,4) == "0320") {
          //  Batterie Sensor Temperatur
          $Ergebnis["BatterieSenseTemp"] = ($this->hexdecs(substr($Daten,10,2).substr($Daten,8,2))/100);
          if ( $Ergebnis["BatterieSenseTemp"] == 327.67) {
            $Ergebnis["BatterieSenseTemp"] = 0;
          }
          elseif ( $Ergebnis["BatterieSenseTemp"] == 327.68) {
            $Ergebnis["BatterieSenseTemp"] = 0;
          }
        }
        else {
          $Ergebnis["Hexwerte"] = $Daten;
        }
      break;


      case 8:
        if (substr($Daten,2,4) == "9EED") {
          if (substr($Daten,6,2) == "00") {
            $Ergebnis["HEX Mode"] = "NEIN";
          }
          else {
            $Ergebnis["HEX Mode"] = "JA";
          }
        }
      break;

      case 'A':

      break;

      default:
        $Ergebnis["Error"] = $Daten;
      break;
    }

    return $Ergebnis;
  }


  function ve_fehlermeldung($ErrorCode) {
    switch ($ErrorCode) {
      case 2:
        $Text = "Batteriespannung zu hoch.";
      break;
      case 17:
        $Text = "Temperatur des Reglers ist zu hoch.";
      break;
      case 18:
        $Text = "Ladestrom zu hoch.";
      break;
      case 19:
        $Text = "Polarität vertauscht.";
      break;
      case 20:
        $Text = "Maximale Ladezeit überschritten.";
      break;
      case 21:
        $Text = "Charger current sensor issue.";
      break;
      case 26:
        $Text = "Regler Anschlüsse sind zu heiß.";
      break;
      case 33:
        $Text = "Eingangsspannung ist zu hoch.";
      break;
      case 38:
        $Text = "Input shutdown.";
      break;
      case 67:
        $Text = "BMS connection lost.";
      break;
      case 116:
        $Text = "Calibration data lost.";
      break;
      case 117:
        $Text = "Incompatible firmware.";
      break;
      case 119:
        $Text = "Settings data invalid.";
      break;
      default:
        $Text = "unbekannter Fehler";
      break;
    }

    return $Text;
  }


  /**************************************************************************
  //  Joulie 16    Joulie 16    Joulie 16    Joulie 16    Joulie 16
  //  BMS Joulie 16 Routinen
  //
  **************************************************************************/
  function joulie_auslesen($USB,$Input) {

    $Timestamp = time();
    $Ergebnis = array();
    $Antwort = "";
    $Dauer = 2; // Sekunden

    stream_set_blocking( $USB , false );

    if (strtolower($Input) <> "trace") {

      fputs ($USB,$Input."\r\n");
      usleep(100000);  // 0,1 Sekunde
    }

    do {

      $Antwort .= fgets($USB,4096); // 4096

      if (trim($Antwort) == "AB>>" ) {
        $Ergebnis[0] = $Antwort."  ".$Ergebnis[0];
        break;
      }
      elseif (trim($Antwort) == "Switched off FW trace" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "Switched on FW trace" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (substr(trim($Antwort),0,36) == "Stop auto balancing before" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "stopped auto balancing" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "auto balancing is already stopped" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "only accessible for service-user" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "unlocked for service!" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "Switched on relay" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "Switched off relay" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "started auto balancing" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (trim($Antwort) == "auto balancing is already running" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (substr(trim($Antwort),0,11) == "last error:" ) {
        $Ergebnis[0] = trim($Antwort);
      }
      elseif (substr(trim($Antwort),0,10) == ">>>>>>>>>>" ) {
        $Ergebnis[99] = trim($Antwort);
      }

      if (substr($Antwort,-1) == "\n") {
        // echo $Antwort."\n";
        $Teile = explode(";",$Antwort);
        if (count($Teile) == 38 and strtolower($Input) == "trace") {
          // print_r($Teile);
          $Ergebnis = $Teile;
          break;
        }
        // echo $Antwort."\n";
        if (isset($Ergebnis[1])) {
        $Ergebnis[1] .= $Antwort;
        }
        else {
          $Ergebnis[1] = $Antwort;
        }
        $Antwort = "";
      }

      if ($Antwort <> "") {
        // echo $Antwort;
      }

    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking ( $USB , true );

    return $Ergebnis;
  }




  function joulie_zahl($Wert) {

    switch ($Wert) {
      case "I":
        $Zahl = "0";
      break;
      case "C":
        $Zahl = "1";
      break;
      case "D":
        $Zahl = "2";
      break;
    }

    return $Zahl;

  }

  function joulie_outb($Wert) {

    $Ausgabe = array();

    $rc = explode("]",$Wert);

    $k=1;
    for ($i=1; $i<= 16; $i++) {

      $ret = explode(" ",$rc[$i]);
      $Ausgabe[$k] = substr($ret[1],0,4);
      $k++;
      $Ausgabe[$k] = substr($ret[2],1,1);
      $k++;
    }

    $rc = explode("\n",$Wert);

    for ($i=1; $i<= count($rc); $i++) {

      if (substr($rc[$i],0,7) == "Current") {
        $Ausgabe[33] = substr(trim($rc[$i]),10,-9);
      }
      if (substr($rc[$i],0,7) == "Voltage") {
        $Ausgabe[36] = substr(trim($rc[$i]),10,-2);
      }
    }

    $Ausgabe[34] = 0;
    $Ausgabe[35] = 0;

    return $Ausgabe;

  }





  /**************************************************************************
  //  Pushover Meldungen senden
  //  noetige Unterroutinen
  //
  **************************************************************************/

  function po_influxdb_lesen($abfrage) {


    $ch = curl_init('http://localhost/query?epoch=s&'.$abfrage["Query"]);

    $i = 1;
    do {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);                //timeout in second s
      curl_setopt($ch, CURLOPT_PORT, "8086");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $result = curl_exec($ch);
      $rc_info = curl_getinfo ($ch);
      $Ausgabe = json_decode($result,true);

      if (curl_errno($ch)) {
        $this->log_schreiben("Curl Fehler! Meldungsdaten nicht von der InfluxDB gelesen! No. ".curl_errno($ch),"   ",5);
      }
      elseif ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
        $this->log_schreiben("Meldungsdaten von der InfluxDB  gelesen. ","*  ",9);
        break;
      }
      elseif(empty($Ausgabe["error"])) {
        $this->log_schreiben("InfluxDB Fehler -> nochmal versuchen.","   ",5);
        $i++;
        continue;
      }
      $this->log_schreiben("Daten nicht von der InfluxDB gelesen! => [ ".$Ausgabe["error"]." ]","   ",5);
      $this->log_schreiben("InfluxDB  => [ ".$abfrage['Query']." ]","   ",5);
      $this->log_schreiben("Daten => [ ".print_r($abfrage,1)." ]","   ",9);
      $this->log_schreiben("Daten nicht von der InfluxDB gelesen! info: ".var_export($rc_info,1),"   ",9);
      $i++;
      sleep(1);
    } while ($i < 3);

    curl_close($ch);
    return $Ausgabe;

  }


  function po_send_message($APP_ID, $UserToken, $Message,$Bild=0) {

    curl_setopt_array($ch = curl_init(), array(
    CURLOPT_URL => "https://api.pushover.net/1/messages.json",
    CURLOPT_POSTFIELDS => array(
    "token" => $APP_ID,
    "user" => $UserToken,
    "html" => 1,
    "message" => $Message,
    )));
    $result = curl_exec($ch);
    $rc_info = curl_getinfo ($ch);
    $Ausgabe = json_decode($result,true);

    if (curl_errno($ch)) {
      $this->log_schreiben("Curl Fehler! Daten nicht zur entfernten InfluxDB gesendet! Curl ErrNo. ".curl_errno($ch),"   ",5);
      return false;
    }
    if ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
      $this->log_schreiben("Daten zur InfluxDB  gesendet. ","*  ",9);
    }
    elseif ($rc_info["http_code"] == 401) {
      $this->log_schreiben("Influx UserID oder Kennwort ist falsch.","*  ",5);
        return false;
    }
    curl_close($ch);

    return true;
  }

  function po_messageControl($Meldung,$Nummer=0,$GeraeteNummer=1) {
    Global $Pfad;
    $Neu = true;
    $Teile = array();
    /************************************************************************
    //  Die Status Datei wird dazu benutzt, um zu verhindern, dass eine
    //  Meldung zu oft gesendet wird.
    //
    ************************************************************************/
    $StatusFile = $Pfad."/database/".$GeraeteNummer.".meldungen.txt";
    if (file_exists($StatusFile)) {
      /************************************************************************
      //  Daten einlesen ...
      ************************************************************************/
      $MeldungenRaw = file_get_contents($StatusFile);

      $Meldungen = explode("\n",$MeldungenRaw);

      $this->log_schreiben(print_r($Meldungen,1),"   ",10);

      $Anzahl = count($Meldungen);
      for ($i = 0; $i < $Anzahl; $i++) {

        $Teile[$i] = explode("|",$Meldungen[$i]);

        $this->log_schreiben($i.": ".print_r($Teile[$i],1),"   ",10);
        if ($Nummer == 0 and date("H:i") != "00:00")  {
          if ($Teile[$i][2] == $Meldung) {
            return $Teile[$i];
          }
        }
        elseif(date("H:i") == "00:00") {
          //  Um Mitternacht wird die Anzahl von allen Einträgen auf 0 zurück gesetzt.
          $Teile[$i][1] = 0;
          $Neu = false;
        }
        else  {
          if ($Teile[$i][2] == $Meldung) {
            $Teile[$i][0] = time();
            $Teile[$i][1] = $Nummer;
            $Neu = false;
          }
        }
      }
      if (date("H:i") == "00:00")  {
        $Nummer = 0;
      }
      elseif ($Nummer == 0)  {
        return false;
      }

      for ($i = 0; $i < $Anzahl; $i++) {

        if ($i == 0) {
          $rc = file_put_contents($StatusFile,$Teile[$i][0]."|".$Teile[$i][1]."|".$Teile[$i][2]);
        }
        else  {
          $rc = file_put_contents($StatusFile,"\n".$Teile[$i][0]."|".$Teile[$i][1]."|".$Teile[$i][2],FILE_APPEND);
        }
      }
      if ($Neu) {
        if ($Nummer == 0)  {
          $rc = file_put_contents($StatusFile,"\n"."1000000000|".$Nummer."|".$Meldung,FILE_APPEND);
        }
        else {
          $rc = file_put_contents($StatusFile,"\n".time()."|".$Nummer."|".$Meldung,FILE_APPEND);
        }
      }

    }
    else {
      /***************************************************************************
      //  Inhalt der Status Datei anlegen.
      //  Die Meldungen müssen mit einem NL (\n) getrennt werden.
      ***************************************************************************/
      $rc = file_put_contents($StatusFile,"1000000000|".$Nummer."|".$Meldung);
      if ($rc === false) {
        $this->log_schreiben("Fehler! Konnte die Datei meldungen.txt nicht anlegen.","XX ",1);
        return false;
      }
    }
    return true;
  }


  function solaredge_lesen($COM, $Input="") {

    $TransactionIdentifier = "0001";
    $ProtocilIdentifier = "0000";
    $MessageLenght = str_pad(dechex(strlen($Input)/2),4,"0",STR_PAD_LEFT);

    $sendenachricht = hex2bin($TransactionIdentifier.$ProtocilIdentifier.$MessageLenght.$Input);

    $rc = fwrite($COM, $sendenachricht);
    $Antwort = bin2hex(fread($COM,1000));      // 1000 Bytes lesen

    return $Antwort;
  }

  /*************************************************************************
  //
  //  SolarEdge    SolarEdge       SolarEdge       SolarEdge       SolarEdge
  //
  *************************************************************************/

  function solaredge_faktor($wert,$hex)  {

    $Faktor = 0;

    if ($wert == 65535)  {    //  Hex FFFF
      return "0";
    }

    // ignore non hex characters
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);

    // converted decimal value:
    $dec = hexdec($hex);

    //   maximum decimal value based on length of hex + 1:
    //   number of bits in hex number is 8 bits for each 2 hex -> max = 2^n
    //   use 'pow(2,n)' since '1 << n' is only for integers and therefore limited to integer size.
    $max = pow(2, 4 * (strlen($hex) + (strlen($hex) % 2)));

    // complement = maximum - converted hex:
    $_dec = $max - $dec;

    // if dec value is larger than its complement we have a negative value (first bit is set)
    if ($dec > $_dec) {
      $zahl = $dec;
    }
    else {
      $zahl = -$dec;
    }

    switch ($zahl) {

      case 0:
        $Faktor = $wert;
      break;
      case -1:
        $Faktor = $wert/10;
      break;
      case -2:
        $Faktor = $wert/100;
      break;
      case -3:
        $Faktor = $wert/1000;
      break;
      case -4:
        $Faktor = $wert/10000;
      break;
      case -5:
        $Faktor = $wert/100000;
      break;
      case -6:
        $Faktor = $wert/1000000;
      break;
      case 1:
        $Faktor = $wert*10;
      break;
      case 2:
        $Faktor = $wert*100;
      break;
      case 3:
        $Faktor = $wert*1000;
      break;
      case 4:
        $Faktor = wert*10000;
      break;
      case 5:
        $Faktor = wert*100000;
      break;
      case 6:
        $Faktor = wert*1000000;
      break;
    }
    return $Faktor;
  }



  /**************************************************************************
  //  Rover / Toyo / SRNE  Laderegler        Rover / Toyo / SRNE  Laderegler
  //  Rover / Toyo / SRNE  Laderegler        Rover / Toyo / SRNE  Laderegler
  //  Hier wird der RS845 Bus ausgelesen. Diese Routine ist sehr zeitkritisch
  //  Bitte die usleep() Funktionen nicht verändern, zumindest erst nach
  //  längeren Testreihen.
  //  Bei schnelleren Raspberry Pi Rechnern müssen die zeitlichen Funktionen
  //  eventuell angepasst werden.
  //  $Input["DeviceID"]
  //  $Input["BefehlFunctionCode"]
  //  $Input["RegisterAddress"]
  //  $Input["RegisterCount"]
  **************************************************************************/
  function renogy_auslesen($USB,$Input) {

    stream_set_blocking ( $USB , false );

    $BefehlBin = hex2bin($Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"]);

    $BefehlBin = $BefehlBin.$this->crc16($BefehlBin);

    // Befehl in HEX!
    // echo $Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"];
    $Laenge = strlen($BefehlBin);

    for ($k = 1; $k < 3; $k++) {
      $buffer = fgets($USB, 500);
      $buffer = "";
      fputs ($USB, $BefehlBin);
      usleep(50000);  // 0,05 Sekunden warten

      for ($i = 1; $i < 50; $i++) {

        $buffer .= fgets($USB, 100);
        usleep(100000);
        // echo $i." ".bin2hex($buffer)."\n";
        if (substr($buffer,0,$Laenge) == $BefehlBin) {
          // echo "Echo erhalten ".$i."\n";
          $buffer = substr($buffer,$Laenge);
          $buffer = "";
        }

        if (substr($buffer,0,2) == substr($BefehlBin,0,2)) {
          if ( substr(bin2hex($buffer),0,4) == "0183") {
            break;
          }
          if (strlen($buffer) == (hexdec(substr(bin2hex($buffer),4,2)) + 5)) {
            // echo "Länge: ".(hexdec(substr(bin2hex($buffer),4,2)) + 5)."\n";
            // echo "Ausgang: ".$i."\n";
            stream_set_blocking ( $USB , true );
            return bin2hex($buffer);
          }
        }
        elseif(strlen($buffer) > 0) {
          // echo "break2\n";
          break;
        }
      }

    }

    stream_set_blocking ( $USB , true );
    return false;
  }

  /******************************************************************************
  //  Umwandlung der Binärdaten in lesbare Form
  ******************************************************************************/
  function renogy_daten($daten, $Dezimal = true, $Times = false) {

    $DeviceID = substr($daten,0,2);
    $BefehlFunctionCode = substr($daten,2,2);
    $RegisterCount = substr($daten,4,2);

    if ($Dezimal) {
      $Ergebnis = hexdec(substr($daten,6,($RegisterCount*2)));
    }
    else {
      $Ergebnis = substr($daten,6,($RegisterCount*2));
    }

    if ($Times == true) {
      $Ergebnis = $Ergebnis/100;
    }
    return $Ergebnis;
  }



  /******************************************************************
  //  AEconversion    AEconversion    AEconversion    AEconversion
  //
  //  AEconversion Inverter auslesen
  //
  ******************************************************************/
  function aec_inverter_lesen($Device,$Befehl='') {

    $CR = "\r"; // CR
    $Antwort = "";
    $OK = false;

    stream_set_blocking($Device, false);  // Es wird auf keine Daten gewartet.

    if (empty($Befehl)) {
      $rc = fwrite($Device,$CR);
      usleep(500000);  // 1/2 Sekunde
      $Antwort = fgets($Device,8192);
      $Dauer = 0;  // nur 1 Durchlauf
    }
    else {
      $Dauer = 3;  // 4 Sekunden
      $rc = fwrite($Device,$Befehl.$CR);
      sleep(1);  //   1 Sekunde
    }

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";

    do {

      $Antwort = fgets($Device,8192);


      if (trim($Antwort) == "") {
        // echo "Nichts empfangen .. \n";
        usleep(5000);
        $Antwort = "";
      }
      else {
        $Ergebnis .= $Antwort;

        // echo "Länge: ".strlen($Ergebnis)."\n".$Antwort."\n";

        if (substr($Ergebnis,1,3) == substr($Befehl,1,3)) {
          $Dauer = 0; // Ausgang vorbereiten..
          // echo $Ergebnis."\n";
          $OK = true;
        }
      }


    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking($Device, true);

    if ($OK == true) {
      return $Ergebnis;
    }
    return false;
  }

  
  /******************************************************************
  //  InfiniSolar V Serie     InfiniSolar V Serie      InfiniSolar V 
  //  Auslesen des InfiniSolar V Serie Wechselrichter
  //
  ******************************************************************/
  function infini_lesen($USB,$Input) {

    stream_set_blocking ( $USB , false );
    $Antwort = "";

    // Der CRC Wert wird errechnet  (CRC/XMODEM)
    $CRC = dechex($this->CRC16Normal($Input));
    $Input2 = chr(hexdec(substr($CRC,0,2))).chr(hexdec(substr($CRC,2,2))).chr(hexdec("0D"));

  //  Die folgenden 2 Zeilen dienen der Syncronisation der Hidraw Schnittstelle und sollten
  //  bestehen bleiben.
  fputs ($USB, "\n");
  fgets($USB,50); // 10


  // Der Befehl wird gesendet...
  fputs ($USB, $Input);
  usleep(10000);   // 10000
  // Der CRC Wert und CR wird gesendet
  fputs ($USB, $Input2);
  usleep(10000);   //  [normal 10000] Es dauert etwas, bis die ersten Daten kommen ...


  for ($k = 1 ; $k < 200; $k++) {
    $rc = fgets($USB,1024); // 4096
    usleep(35000);  // 30000
    $Antwort .= $rc;
    // echo bin2hex($rc)."\n";
    if (substr($Antwort,0,2)  == "^D" and strlen($Antwort) > 5) {
      if (strlen($Antwort) > substr($Antwort,2,3)) {
        $Laenge = (substr($Antwort,2,3) -3);
        stream_set_blocking ( $USB , true );
        return substr($Antwort,5,$Laenge);
      }
    }
    if (substr($Antwort,0,2)  == "^0") {
      break;
    }
  }
  stream_set_blocking ( $USB , true );
  // echo bin2hex($Antwort)."\n";

  return false;
}


  /**************************************************************
  //   InfiniSolar Daten entschlüsseln
  //
  **************************************************************/

  function infini_entschluesseln($Befehl,$Daten) {

    $Ergebnis = array();

    switch ($Befehl) {

      case "GS":
        $Teile = explode(",",$Daten);
        $Ergebnis["Netzspannung"] = ($Teile[0]/10);
        $Ergebnis["Netzfrequenz"] = ($Teile[1]/10);
        $Ergebnis["AC_Ausgangsspannung"] = ($Teile[2]/10);
        $Ergebnis["AC_Ausgangsfrequenz"] = ($Teile[3]/10);
        $Ergebnis["AC_Scheinleistung"] = $Teile[4];
        $Ergebnis["AC_Wirkleistung"] = $Teile[5];
        $Ergebnis["Ausgangslast"] = $Teile[6];
        $Ergebnis["Batteriespannung"] = ($Teile[7]/10);
        $Ergebnis["Batterieentladestrom"] = ($Teile[10]/10);
        $Ergebnis["Batterieladestrom"] = ($Teile[11]/10);
        $Ergebnis["Batteriekapazitaet"] = ($Teile[12]/10);
        $Ergebnis["Temperatur"] = $Teile[13];
        $Ergebnis["MPPT1_Temperatur"] = $Teile[14];
        $Ergebnis["MPPT2_Temperatur"] = $Teile[15];
        $Ergebnis["Solarleistung1"] = $Teile[16];
        $Ergebnis["Solarleistung2"] = $Teile[17];
        $Ergebnis["Solarspannung1"] = ($Teile[18]/10);
        $Ergebnis["Solarspannung2"] = ($Teile[19]/10);
        $Ergebnis["Ladestatus1"] = $Teile[21];
        $Ergebnis["Ladestatus2"] = $Teile[22];
        $Ergebnis["Batteriestromrichtung"] = $Teile[23];
        $Ergebnis["WR_Stromrichtung"] = $Teile[24];
        $Ergebnis["Netzstromrichtung"] = $Teile[25];
      break;

      case "MOD":
        $Ergebnis["Modus"] = $Daten;
      break;

      case "PI":
        $Ergebnis["Firmware"] = $Daten;
      break;

      case "T":
       // Hier kann Datum und Uhrzeit entschlüsselt werden
        $Ergebnis["Stunden"] = substr($Daten,8,2);
        $Ergebnis["Minuten"] = substr($Daten,10,2);
        $Ergebnis["Sekunden"] = substr($Daten,12,2);
      break;

      case "FWS":
        $Warnungen = array();
        $k = 1;
        $Ergebnis["Warnungen"] = 0;
        $Teile = explode(",",$Daten);
        $Ergebnis["Fehlercode"] = $Teile[0];
        for ($i = 1; $i < 16; $i++) {
          if ($Teile[$i] == 1) {
            // Es gibt eine oder mehrere Warnungen
            $Warnungen[$k] = $i;
            $k++;
          }
        }
        $k = ($k-1);
        if ($k == 1) {
          $Ergebnis["Warnungen"] = $Warnungen[$k];

        }
        elseif ($k > 1){
          //  Es sind mehrere Warnungen vorhanden
          $Ergebnis["Warnungen"] = $Warnungen[rand(1,$k)];
        }
      break;
    }
    return $Ergebnis;
  }



  /****************************************************************
  //   Die IVT Regler USB Schnittstelle auslesen
  //
  ****************************************************************/
  function ivt_lesen($Device,$Befehl='') {

    $CR = "\n"; // CR LF -> eventuell löschen
    $Antwort = "";

    stream_set_blocking($Device, false);  // Es wird auf keine Daten gewartet.

    if (empty($Befehl)) {
      $Antwort = fgets($Device,8192);
      $Dauer = 0;  // nur 1 Durchlauf
    }
    else {
      $Dauer = 4;  // 4 Sekunden

      $rc = fwrite($Device,$Befehl.$CR);
      usleep(500000);  // 1/2 Sekunde
    }

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";

    do {

      $Antwort = fgets($Device,4096);

      if (trim($Antwort) == "") {
        //    echo "Nichts empfangen .. \n";
        usleep(500000);
        $Antwort = "";
      }
      else {
        $Ergebnis .= $Antwort;
        // echo time()." ".bin2hex($Ergebnis);
        if (substr($Ergebnis,0,18) == "solar.pc.senddata." and strlen(bin2hex($Ergebnis)) == 74 ) {
          $Dauer = 0; // Ausgang vorbereiten..
        }
      }

    } while (($Timestamp + $Dauer) > time());


    stream_set_blocking($Device, true);

    if (strlen(bin2hex($Ergebnis)) == 74) {
      // Daten
      return $Ergebnis;
    }

    if (strlen(bin2hex($Ergebnis)) == 4) {
      // ReglerTyp
      return $Ergebnis;
    }

    return false;
  }


  /******************************************************************
  //   Die IVT Daten entschlüsseln
  //
  ******************************************************************/

  function ivt_entschluesseln($rc) {


    $Ergebnis = array();
    $Ergebnis["Objekt"] = "";

    if (substr($rc,0,6) != "solar.") {
      return false;
    }
    $rc = substr($rc,6);
    if (substr($rc,0,3) != "pc.") {
      return false;
    }
    $rc = substr($rc,3);

    if (substr($rc,0,11) == "sendparams.") {
      $rc = bin2hex(substr($rc,11));

      $Ergebnis["Volt1"] = number_format((hexdec(substr($rc,0,2))+79)/10,2,",","");
      $Ergebnis["Volt2"] = number_format((hexdec(substr($rc,2,2))+79)/10,2,",","");
      $Ergebnis["Volt3"] = number_format((hexdec(substr($rc,4,2))+79)/10,2,",","");
      $Ergebnis["Min1"] = number_format((hexdec(substr($rc,6,2))-1)*15,2,",","");
      $Ergebnis["Min2"] = number_format((hexdec(substr($rc,8,2))-1)*15,2,",","");
      $Ergebnis["MinVolt"] = number_format((hexdec(substr($rc,10,2))+79)/10,2,",","");
      $Ergebnis["MaxVolt"] = number_format((hexdec(substr($rc,12,2))+79)/10,2,",","");
      $Ergebnis["Temp"] = (hexdec(substr($rc,14,2))-1);
      $Ergebnis["Load_on_H"] = (hexdec(substr($rc,16,2))-1);
      $Ergebnis["Load_on_M"] = (hexdec(substr($rc,18,2))-1);
      $Ergebnis["Load_off_H"] = (hexdec(substr($rc,20,2))-1);
      $Ergebnis["Load_off_M"] = (hexdec(substr($rc,22,2))-1);
      $Ergebnis["Profile"] = (hexdec(substr($rc,24,2))-1);
      $Ergebnis["Mode"] = substr($rc,26,2);
      $Ergebnis["Jahr"] = (hexdec(substr($rc,28,2)))+1999;
      $Ergebnis["Monat"] = (hexdec(substr($rc,30,2)))-1;
      $Ergebnis["Tag"] = (hexdec(substr($rc,32,2)))-1;
      $Ergebnis["Stunden"] = (hexdec(substr($rc,34,2)))-1;
      $Ergebnis["Minuten"] = (hexdec(substr($rc,36,2)))-1;
      $Ergebnis["Sekunden"] = (hexdec(substr($rc,38,2)))-1;
      $Ergebnis["Ah1"] = (hexdec(substr($rc,40,2)))-1;
      $Ergebnis["Ah2"] = (hexdec(substr($rc,42,2))-1)/100;
      $Ergebnis["kwh1"] = (hexdec(substr($rc,44,2)))-1;
      $Ergebnis["kwh2"] = (hexdec(substr($rc,46,2))-1)/100;
    }

    if (substr($rc,0,9) == "senddata.") {
      $rc = bin2hex(substr($rc,9));
      $Ergebnis["BatVL"] = (hexdec(substr($rc,0,2))-1);
      $Ergebnis["BatVR"] = (hexdec(substr($rc,2,2))-1);
      $Ergebnis["SolarVL"] = (hexdec(substr($rc,4,2))-1);
      $Ergebnis["SolarVR"] = (hexdec(substr($rc,6,2))-1);
      $Ergebnis["SolarAL"] = (hexdec(substr($rc,8,2))-1);
      $Ergebnis["SolarAR"] = (hexdec(substr($rc,10,2))-1);
      $Ergebnis["LoadAL"] = (hexdec(substr($rc,12,2))-1);
      $Ergebnis["LoadAR"] = (hexdec(substr($rc,14,2))-1);
      $Ergebnis["ahGesamtL"] = (hexdec(substr($rc,16,2))-1);
      $Ergebnis["ahGesamtR"] = (hexdec(substr($rc,18,2))-1);
      $Ergebnis["kwhGesamtL"] = (hexdec(substr($rc,20,2))-1);
      $Ergebnis["kwhGesamtR"] = (hexdec(substr($rc,22,2))-1);
      $Ergebnis["TempInt"] = (hexdec(substr($rc,24,2))-1);
      $Ergebnis["TempVorzeichen"] = 0;
      $Ergebnis["TempExt"] = (hexdec(substr($rc,26,2))-11);
      $Ergebnis["Stunden"] = (hexdec(substr($rc,28,2)))-1;
      $Ergebnis["Minuten"] = (hexdec(substr($rc,30,2)))-1;
      $Ergebnis["Sekunden"] = (hexdec(substr($rc,32,2)))-1;
      $Ergebnis["Nummer"] = 0;
    }

    if (substr($rc,0,9) == "sendtype.") {
      $rc = bin2hex(substr($rc,9));
      $Ergebnis["Alle"] = $rc;

    }


    return $Ergebnis;
  }

  /**************************************************************************
  //  KOSTAL       KOSTAL       KOSTAL       KOSTAL       KOSTAL       KOSTAL
  //  KOSTAL       KOSTAL       KOSTAL       KOSTAL       KOSTAL       KOSTAL
  //  Auslesen des Kostal Wechselrichter
  //
  **************************************************************************/

  function kostal_com_lesen($COM, $Input="") {

    $Antwort = "";
    $TransactionIdentifier = "0001";
    $ProtocilIdentifier = "0000";
    $MessageLenght = str_pad(dechex(strlen($Input)/2),4,"0",STR_PAD_LEFT);

    $sendenachricht = hex2bin($TransactionIdentifier.$ProtocilIdentifier.$MessageLenght.$Input);

    $rc = fwrite($COM, $sendenachricht);

    do  {
      // Manche Geräte senden die Daten in Häppchen.
      $Antwort .= bin2hex(fread($COM,1000));      // 1000 Bytes lesen
      usleep(1000);
      $Laenge = ((hexdec(substr($Antwort,8,4))*2)+12);  // 12 = Header Länge
    } while (strlen($Antwort) < $Laenge);

    return $Antwort;
  }



  function kostal_register_lesen($COM1,$Register,$Laenge,$Typ) {

    $GeraeteAdresse = "47"; // Dec 71
    $Befehl = "03";

    $rc = $this->kostal_com_lesen($COM1,$GeraeteAdresse.$Befehl.$Register.$Laenge);

    $Daten["Register"] = $Register;
    $Daten["RawDaten"] = $rc;
    $Daten["Transaction"] = substr($rc,0,4);
    $Daten["Protocol"] = substr($rc,4,4);
    $Daten["Laenge"] = substr($rc,8,4);
    $Daten["Adresse"] = substr($rc,12,2);
    $Daten["Befehl"] = substr($rc,14,2);
    $Daten["DatenLaenge"] = hexdec(substr($rc,16,2));
    $Daten["Wert"] = 0;

    if ($Daten["Transaction"] == "0001" and $Daten["Adresse"] == "47" and $Daten["Befehl"] == "03") {
      //  Prüfen ob der Wert richtig sein kann.

      switch ($Typ) {

        case "String":
          $Daten["Wert"] = $this->Hex2String(substr($rc,18,$Daten["DatenLaenge"]));
        break;
        case "U16-1":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "U16":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]));
          // $Daten["Wert"] = hexdec(substr($rc,22,$Daten["DatenLaenge"]).substr($rc,18,$Daten["DatenLaenge"]));
        break;
        case "S16":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]));
          if ($Daten["Wert"] > 32767) {                                     //  Eingefügt 22.09.2020
          	$Daten["Wert"] = $Daten["Wert"] - 65536;
          }
        break;
        case "Float":
          $Daten["Wert"] = round($this->hex2float(substr($rc,22,$Daten["DatenLaenge"]).substr($rc,18,$Daten["DatenLaenge"])),2);
        break;
        case "U32":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "U64":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "String2":
          $Daten["Wert"] = $this->Hex2String(trim(substr($rc,18,$Daten["DatenLaenge"]*2)));
        break;
        case "Hex":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        default:
          $Daten["Wert"] = substr($rc,18,$Daten["DatenLaenge"]*2);
        break;
      }

    }

    return $Daten;
  }


  function kostal_auslesen($USB,$Input,$Laenge) {

    stream_set_blocking ( $USB , false );

    $BefehlBin = hex2bin($Input);

    fputs ($USB, $BefehlBin);
    usleep(50000);  // 0,05 Sekunden warten


    $buffer = ""; // Buffer leeren

    for ($i = 1; $i < 10; $i++) {

      $buffer .= fgets($USB, 4092);
      usleep(1000);

      $Antwort =  bin2hex($buffer);
      if (strlen($Antwort) >=  ($Laenge*2)) {
        $Antwort =  substr($Antwort,strpos($Antwort,"05"));
        if (strpos($Antwort,"00",($Laenge*2-2))) {
          $Antwort =  substr($Antwort,0,strpos($Antwort,"00",($Laenge*2-2))+2);
        }
      }

      // echo "\n";
      // echo $i." ".$Input."\n";
      // echo $i." ".$Antwort."\n";
      // echo "\n";

      if (substr($Antwort,0,2) == "05" and strlen($Antwort) == $Laenge*2 ) {
        stream_set_blocking ( $USB , true );
        return $Antwort;
      }
      else {
        usleep(20000);
      }
    }


    $BefehlBin = hex2bin($Input);

    fputs ($USB, $BefehlBin);
    usleep(50000);  // 0,05 Sekunden warten


    $buffer = ""; // Buffer leeren

    for ($i = 1; $i < 10; $i++) {

      $buffer .= fgets($USB, 4092);
      usleep(1000);

      $Antwort =  bin2hex($buffer);
      if (strlen($Antwort) >=  ($Laenge*2)) {
        $Antwort =  substr($Antwort,strpos($Antwort,"05"));
        if (strpos($Antwort,"00",($Laenge*2-2))) {
          $Antwort =  substr($Antwort,0,strpos($Antwort,"00",($Laenge*2-2))+2);
        }
      }

      if (substr($Antwort,0,2) == "05" and strlen($Antwort) == $Laenge*2 ) {
        stream_set_blocking ( $USB , true );
        return $Antwort;
      }
      else {
        usleep(20000);
      }
    }

    stream_set_blocking ( $USB , true );
    return false;
  }



  function kostal_umwandlung($Wert) {

    $Ergebnis = "";

    switch (strlen($Wert)) {
      case 2:
        $Ergebnis = hexdec(substr($Wert,0,2));
      break;
      case 4:
        $Ergebnis = hexdec(substr($Wert,2,2).substr($Wert,0,2));
      break;
      case 8:
        $Ergebnis = hexdec(substr($Wert,6,2).substr($Wert,4,2).substr($Wert,2,2).substr($Wert,0,2));
      break;

    }
    return $Ergebnis;
  }


  function cobs_decoder($Wert) {

    $Ergebnis = $Wert;

    $cobs = hexdec(substr($Wert,10,2));
    if (strlen($Wert) != ($cobs*2)+12) {

      $Laenge = strlen($Wert);
      $Zaehler = 10;

      for ($i=0;$i <= strlen($Wert); $i++) {

        $Zaehler = $Zaehler + ($cobs*2);
        if (substr($Wert,$Zaehler,2) == "00") {
          break;
        }
        $Ergebnis = substr_replace ( $Ergebnis , "00" , $Zaehler, 2);
        $cobs = hexdec(substr($Wert,$Zaehler,2));

        // echo $Zaehler."\n";
        // echo $Wert."\n";
        // echo $Ergebnis."\n\n";

      }
    }

    return $Ergebnis;
  }

  /****************************************************************************
  //  MODBUS      MODBUS      MODBUS      MODBUS      MODBUS      MODBUS
  //
  //  Funktionen für Modbus Geräte
  //
  ****************************************************************************/

  function modbus_register_lesen($COM1,$Register,$Laenge,$Typ,$GeraeteAdresse,$Befehl="03") {

    if (strlen($Register) == 5) {
      $Register = dechex($Register);
    }
    else {
      $Register = str_pad(dechex($Register),4,"0",STR_PAD_LEFT);
    }

    $rc = $this->kostal_com_lesen($COM1,$GeraeteAdresse.$Befehl.$Register.$Laenge);

    $Daten["Register"] = $Register;
    $Daten["RawDaten"] = $rc;
    $Daten["Transaction"] = substr($rc,0,4);
    $Daten["Protocol"] = substr($rc,4,4);
    $Daten["Laenge"] = substr($rc,8,4);
    $Daten["Adresse"] = substr($rc,12,2);
    $Daten["Befehl"] = substr($rc,14,2);
    $Daten["DatenLaenge"] = hexdec(substr($rc,16,2));
    $Daten["Wert"] = 0;
    $Daten["KeineSonne"] = false;



    if (substr($rc,18,8) == "ffffffff") {
          $Daten["Wert"] = "0.0";
          $Daten["KeineSonne"] = true;

    }
    elseif (substr($rc,18,8) == "80000000") {
          $Daten["Wert"] = "0.00";
          $Daten["KeineSonne"] = true;
    }
    elseif ($Daten["Transaction"] == "0001" and $Daten["Adresse"] == strtolower($GeraeteAdresse) and $Daten["Befehl"] == $Befehl) {
      //  Prüfen ob der Wert richtig sein kann.


      switch ($Typ) {

        case "String":
          $Daten["Wert"] = $this->Hex2String(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "U16-1":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "U16":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]));
        break;
        case "S16":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]));
        break;
        case "S32":
          $Daten["Wert"] = $this->hexdecs(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "Float":
          $Daten["Wert"] = round($this->hex2float(substr($rc,22,$Daten["DatenLaenge"]).substr($rc,18,$Daten["DatenLaenge"])),2);
        break;
        case "U32":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "U64":
          $Daten["Wert"] = hexdec(substr($rc,24,2).substr($rc,26,2).substr($rc,18,2).substr($rc,22,2));
        break;
        case "String2":
          $Daten["Wert"] = $this->Hex2String(trim(substr($rc,18,$Daten["DatenLaenge"]*2)));
        break;
        case "Hex":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;
        case "SF16":
          $SF = hexdec(substr($rc,18,$Daten["DatenLaenge"]));
          switch ($SF) {
             case 3:
               $Daten["Wert"] = 1000;
             break;
             case 2:
               $Daten["Wert"] = 100;
             break;
             case 1:
               $Daten["Wert"] = 10;
             break;
             case 0:
               $Daten["Wert"] = 1;
             break;
             case 65535:
               $Daten["Wert"] = 0.1;
             break;
             case 65534:
               $Daten["Wert"] = 0.01;
             break;
             case 65533:
               $Daten["Wert"] = 0.001;
             break;
          }
        break;
        case "Dec16Bit":
          $Daten["Wert"] = hexdec(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;

        case "Dec32Bit":
          $Daten["Wert"] = hexdec(substr($rc,22,$Daten["DatenLaenge"]).substr($rc,18,$Daten["DatenLaenge"]));
        break;

        case "HexString":
          $Daten["Wert"] = strtoupper(substr($rc,18,$Daten["DatenLaenge"]*2));
        break;

        default:
          $Daten["Wert"] = substr($rc,18,$Daten["DatenLaenge"]*2);
        break;
      }

    }

    return $Daten;
  }



  /****************************************************************************
  //  STECA      STECA      STECA      STECA      STECA      STECA      STECA
  //
  //  Funktionen für die STECA Solarregler
  //
  ****************************************************************************/
  function steca_daten($HEXDaten) {

    $Daten = array();

    $Daten["StartFrame"]  = substr($HEXDaten,0,2);
    $Daten["StartHeader"] = substr($HEXDaten,2,2);
    $Daten["FrameLaenge"] = substr($HEXDaten,4,4);
    $Daten["Empfaenger"]  = substr($HEXDaten,8,2);
    $Daten["Sender"]      = substr($HEXDaten,10,2);
    $Daten["CRCHeader"]   = substr($HEXDaten,12,2);
    $Daten["D_ServiceID"]     = substr($HEXDaten,14,2);
    $Daten["D_AntwortCode"]   = substr($HEXDaten,16,2);
    if (hexdec($Daten["FrameLaenge"]) > 12) {
      $Daten["D_DatenLaenge"]   = substr($HEXDaten,18,4);
      $Daten["D_ServiceCode"]   = substr($HEXDaten,22,2);
      $Daten["D_Daten"]   = substr($HEXDaten,24,(hexdec($Daten["D_DatenLaenge"])*2)-2);
    }
    $Daten["CRCDaten"]    = substr($HEXDaten,-8,2);
    $Daten["CRCFrame"]    = substr($HEXDaten,-6,4);
    $Daten["StopFrame"]   = substr($HEXDaten,-2);
    return $Daten;
  }

  /*****************************************************************************
  //  Hier wird das Datenfeld entschlüsselt. Dazu wird die D_ServiceID,
  //  der D_ServiceCode und die eigentlichen D_Daten benötigt.
  //
  *****************************************************************************/
  function steca_FrameErstellen($FrameDaten)  {

    $Frame = "";
    $Frame .= $FrameDaten["StartFrame"];
    $Frame .= $FrameDaten["StartHeader"];
    $Frame .= $FrameDaten["FrameLaenge"];
    $Frame .= $FrameDaten["Empfaenger"];
    $Frame .= $FrameDaten["Sender"];
    $Frame .= $FrameDaten["CRCHeader"];
    $Frame .= $FrameDaten["D_ServiceID"];
    $Frame .= $FrameDaten["D_Priority"];
    if ($FrameDaten["D_DatenLaenge"]  > "0000") {
      $Frame .= $FrameDaten["D_DatenLaenge"];
      $Frame .= $FrameDaten["D_ServiceCode"];
      $Frame .= $FrameDaten["D_Daten"];
      $Frame .= $FrameDaten["CRCDaten"];
    }
    $Frame .= $FrameDaten["StopFrame"];

    $FrameDaten["FrameLaenge"] = substr("0000".dechex(strlen($Frame.$FrameDaten["CRCFrame"])/2),-4);
    $FrameDaten["CRCHeader"] = $this->crc8($FrameDaten["StartFrame"].$FrameDaten["StartHeader"].$FrameDaten["FrameLaenge"].$FrameDaten["Empfaenger"].$FrameDaten["Sender"]);
    $FrameDaten["CRCDaten"] = $this->crc8Data($FrameDaten["D_ServiceCode"].$FrameDaten["D_Daten"]);

    $Frame = "";
    $Frame .= $FrameDaten["StartFrame"];
    $Frame .= $FrameDaten["StartHeader"];
    $Frame .= $FrameDaten["FrameLaenge"];
    $Frame .= $FrameDaten["Empfaenger"];
    $Frame .= $FrameDaten["Sender"];
    $Frame .= $FrameDaten["CRCHeader"];
    $Frame .= $FrameDaten["D_ServiceID"];
    $Frame .= $FrameDaten["D_Priority"];
    if ($FrameDaten["D_DatenLaenge"]  > "0000") {
      $Frame .= $FrameDaten["D_DatenLaenge"];
      $Frame .= $FrameDaten["D_ServiceCode"];
      $Frame .= $FrameDaten["D_Daten"];
      $Frame .= $FrameDaten["CRCDaten"];
    }
    $Frame .= $FrameDaten["StopFrame"];

    $FrameDaten["CRCFrame"] = $this->crc16_steca($Frame);

    $Frame = "";
    $Frame .= $FrameDaten["StartFrame"];
    $Frame .= $FrameDaten["StartHeader"];
    $Frame .= $FrameDaten["FrameLaenge"];
    $Frame .= $FrameDaten["Empfaenger"];
    $Frame .= $FrameDaten["Sender"];
    $Frame .= $FrameDaten["CRCHeader"];
    $Frame .= $FrameDaten["D_ServiceID"];
    $Frame .= $FrameDaten["D_Priority"];
    if ($FrameDaten["D_DatenLaenge"]  > "0000") {
      $Frame .= $FrameDaten["D_DatenLaenge"];
      $Frame .= $FrameDaten["D_ServiceCode"];
      $Frame .= $FrameDaten["D_Daten"];
      $Frame .= $FrameDaten["CRCDaten"];
    }
    $Frame .= $FrameDaten["CRCFrame"];
    $Frame .= $FrameDaten["StopFrame"];

    return $Frame;
  }




  /*****************************************************************************
  //  Hier wird das Datenfeld entschlüsselt. Dazu wird die D_ServiceID,
  //  der D_ServiceCode und die eigentlichen D_Daten benötigt.
  //
  *****************************************************************************/
  function steca_entschluesseln($ServiceID,$ServiceCode,$StecaDaten,$ReglerModell="MPPT6000")  {
    $Daten = array();
    $Daten["Valid"] = false;

    switch ($ServiceID) {
      case "21":
           $Daten["Valid"] = true;
           $Daten["Text"] = $ServiceCode.$StecaDaten;

      break;
      case "65":
        //  Datum und Uhrzeit entschlüsseln
        if ($ServiceCode == "05") {
           $Daten["Jahr"] = "20".hexdec(substr($StecaDaten,0,2));
           $Daten["Monat"] = hexdec(substr($StecaDaten,2,2));
           $Daten["Tag"] = hexdec(substr($StecaDaten,4,2));
           $Daten["Stunden"] = hexdec(substr($StecaDaten,6,2));
           $Daten["Minuten"] = hexdec(substr($StecaDaten,8,2));
           $Daten["Sekunden"] = hexdec(substr($StecaDaten,10,2));

           /*******************************************************************
           0 = Time invalid
           1 = Time valid
           2 = Time set in last 24 hours
           *******************************************************************/
           $Daten["Valid"] = hexdec(substr($StecaDaten,12,2));
        }
        elseif ($ServiceCode == "e4") {
          // $Daten["Text_Daten"] = $StecaDaten;
          $Daten["Solarstrom"] = (hexdec(substr($StecaDaten,6,2).substr($StecaDaten,4,2).substr($StecaDaten,2,2).substr($StecaDaten,0,2))/1000);
          $Daten["Batteriespannung"] = (hexdec(substr($StecaDaten,14,2).substr($StecaDaten,12,2).substr($StecaDaten,10,2).substr($StecaDaten,8,2))/1000);
          $Daten["Batterieladestrom"] = (hexdec(substr($StecaDaten,22,2).substr($StecaDaten,20,2).substr($StecaDaten,18,2).substr($StecaDaten,16,2))/1000);
          $Daten["SOCProzent"] = hexdec(substr($StecaDaten,24,2));
          if (substr($ReglerModell,0,10) == "Tarom 4545" ) {
            $Daten["Batterieentladestrom"] = (hexdec(substr($StecaDaten,32,2).substr($StecaDaten,30,2).substr($StecaDaten,28,2).substr($StecaDaten,26,2))/1000);
            $Daten["Solarspannung1"] = (hexdec(substr($StecaDaten,40,2).substr($StecaDaten,38,2).substr($StecaDaten,36,2).substr($StecaDaten,34,2))/1000);
            $Daten["Betriebsstunden"] = (hexdec(substr($StecaDaten,48,2).substr($StecaDaten,46,2).substr($StecaDaten,44,2).substr($StecaDaten,42,2)));
            $Daten["Temperatur"] = (hexdec(substr($StecaDaten,56,2).substr($StecaDaten,54,2).substr($StecaDaten,52,2).substr($StecaDaten,50,2))/1000);
            $Daten["BatteriestromGesamt"] = ($this->hexdecs(substr($StecaDaten,64,2).substr($StecaDaten,62,2).substr($StecaDaten,60,2).substr($StecaDaten,58,2))/1000);
            $Daten["GesamtEntladestrom"] = (hexdec(substr($StecaDaten,72,2).substr($StecaDaten,70,2).substr($StecaDaten,68,2).substr($StecaDaten,66,2))/1000);
            $Daten["GesamtLadestrom"] = (hexdec(substr($StecaDaten,80,2).substr($StecaDaten,78,2).substr($StecaDaten,76,2).substr($StecaDaten,74,2))/1000);
            $Daten["Fehlerstatus"] = hexdec(substr($StecaDaten,82,2));
            $Daten["Lademodus"] = substr($StecaDaten,84,2);
            $Daten["StatusLast"] = substr($StecaDaten,86,2);
            $Daten["Relais1"] = substr($StecaDaten,88,2);
            $Daten["Relais2"] = substr($StecaDaten,90,2);
            $Daten["EnergieEingangTag"] = hexdec(substr($StecaDaten,106,2).substr($StecaDaten,104,2).substr($StecaDaten,102,2).substr($StecaDaten,100,2).substr($StecaDaten,98,2).substr($StecaDaten,96,2).substr($StecaDaten,94,2).substr($StecaDaten,92,2));
            $Daten["EnergieEingangGesamt"] = hexdec(substr($StecaDaten,122,2).substr($StecaDaten,120,2).substr($StecaDaten,118,2).substr($StecaDaten,116,2).substr($StecaDaten,114,2).substr($StecaDaten,112,2).substr($StecaDaten,110,2).substr($StecaDaten,108,2));
            $Daten["EnergieAusgangTag"] = hexdec(substr($StecaDaten,138,2).substr($StecaDaten,136,2).substr($StecaDaten,134,2).substr($StecaDaten,132,2).substr($StecaDaten,130,2).substr($StecaDaten,128,2).substr($StecaDaten,126,2).substr($StecaDaten,124,2));
            $Daten["EnergieAusgangGesamt"] = hexdec(substr($StecaDaten,154,2).substr($StecaDaten,152,2).substr($StecaDaten,150,2).substr($StecaDaten,148,2).substr($StecaDaten,146,2).substr($StecaDaten,144,2).substr($StecaDaten,142,2).substr($StecaDaten,140,2));
            $Daten["Derating"] = substr($StecaDaten,156,2);
            $Daten["TemperaturPowerunit1"] = (hexdec(substr($StecaDaten,164,2).substr($StecaDaten,162,2).substr($StecaDaten,160,2).substr($StecaDaten,158,2))/1000);
            $Daten["TemperaturPowerunit2"] = (hexdec(substr($StecaDaten,172,2).substr($StecaDaten,170,2).substr($StecaDaten,168,2).substr($StecaDaten,166,2))/1000);
            $Daten["TagNacht"] = substr($StecaDaten,174,2);
            $Daten["Solarspannung2"] = "0.0";
          }
          else {
            $Daten["SOH"] = hexdec(substr($StecaDaten,28,2).substr($StecaDaten,26,2));
            $Daten["Solarspannung1"] = (hexdec(substr($StecaDaten,36,2).substr($StecaDaten,34,2).substr($StecaDaten,32,2).substr($StecaDaten,30,2))/1000);
            $Daten["Solarspannung2"] = (hexdec(substr($StecaDaten,44,2).substr($StecaDaten,42,2).substr($StecaDaten,40,2).substr($StecaDaten,38,2))/1000);
            $Daten["SolarleistungGesamt"] = (hexdec(substr($StecaDaten,52,2).substr($StecaDaten,50,2).substr($StecaDaten,48,2).substr($StecaDaten,46,2))/1000);
            $Daten["Solarleistung1"] = (hexdec(substr($StecaDaten,60,2).substr($StecaDaten,58,2).substr($StecaDaten,56,2).substr($StecaDaten,54,2))/1000);
            $Daten["Solarleistung2"] = (hexdec(substr($StecaDaten,68,2).substr($StecaDaten,66,2).substr($StecaDaten,64,2).substr($StecaDaten,62,2))/1000);
            $Daten["Betriebsstunden"] = hexdec(substr($StecaDaten,76,2).substr($StecaDaten,74,2).substr($StecaDaten,72,2).substr($StecaDaten,70,2));
            $Daten["Temperatur"] = (hexdec(substr($StecaDaten,84,2).substr($StecaDaten,82,2).substr($StecaDaten,80,2).substr($StecaDaten,78,2))/1000);
            $Daten["Gesamtstrom"] = (hexdec(substr($StecaDaten,92,2).substr($StecaDaten,90,2).substr($StecaDaten,88,2).substr($StecaDaten,86,2))/1000);
            $Daten["Entladestrom"] = (hexdec(substr($StecaDaten,100,2).substr($StecaDaten,98,2).substr($StecaDaten,96,2).substr($StecaDaten,94,2))/1000);
            $Daten["Batterieleistung"] = (hexdec(substr($StecaDaten,108,2).substr($StecaDaten,106,2).substr($StecaDaten,104,2).substr($StecaDaten,102,2))/1000);
            $Daten["Batterieladestrom"] = (hexdec(substr($StecaDaten,116,2).substr($StecaDaten,114,2).substr($StecaDaten,112,2).substr($StecaDaten,110,2))/1000);
            $Daten["Fehler"] = substr($StecaDaten,118,2);
            $Daten["Lademodus"] = substr($StecaDaten,120,2);
            $Daten["Relais1"] = substr($StecaDaten,122,2);
            $Daten["Relais2"] = substr($StecaDaten,124,2);
            $Daten["Relais3"] = substr($StecaDaten,126,2);
            $Daten["EnergieEingangTag"] = hexdec(substr($StecaDaten,134,2).substr($StecaDaten,132,2).substr($StecaDaten,130,2).substr($StecaDaten,128,2));
            $Daten["EnergieEingangGesamt"] = hexdec(substr($StecaDaten,142,2).substr($StecaDaten,140,2).substr($StecaDaten,138,2).substr($StecaDaten,136,2));
            $Daten["EnergieAusgangTag"] = hexdec(substr($StecaDaten,150,2).substr($StecaDaten,148,2).substr($StecaDaten,146,2).substr($StecaDaten,144,2));
            $Daten["EnergieAusgangGesamt"] = hexdec(substr($StecaDaten,158,2).substr($StecaDaten,156,2).substr($StecaDaten,154,2).substr($StecaDaten,152,2));
            $Daten["Derating"] = substr($StecaDaten,160,2);
            $Daten["TemperaturPowerunit1"] = (hexdec(substr($StecaDaten,168,2).substr($StecaDaten,166,2).substr($StecaDaten,164,2).substr($StecaDaten,162,2))/1000);
            $Daten["TemperaturPowerunit2"] = (hexdec(substr($StecaDaten,176,2).substr($StecaDaten,174,2).substr($StecaDaten,172,2).substr($StecaDaten,170,2))/1000);
            $Daten["TagNacht"] = substr($StecaDaten,178,2);
            $Daten["AuxIO"] = substr($StecaDaten,180,2);
          }
          $Daten["Valid"] = true;
        }
      break;
      case "69":

      break;

    }





    return $Daten;
  }

  /****************************************************************************
  //  Hier wird der RS845 Bus ausgelesen. Diese Routine ist sehr zeitkritisch
  //  Bitte die usleep() Funktionen nicht verändern, zumindest erst nach
  //  längeren Testreihen.
  //  Bei schnelleren Raspberry Pi Rechnern müssen die zeitlichen Funktionen
  //  eventuell angepasst werden.
  ****************************************************************************/
  function steca_auslesen($USB,$Befehl) {

    stream_set_blocking ( $USB , false );

    // Befehl in HEX!
    $buffer = "";
    $Befehl = strtolower($Befehl);
    $Laenge = strlen($Befehl);
    // echo "Befehl: ".$Befehl." Länge: ".$Laenge."\n";

    fputs ($USB, hex2bin($Befehl));


    //  Es wird ein Echo erwartet. Das wird in der 1. Runde
    //  erwartet. Das Echo muss im Adapter eingeschaltet sein.
    for ($i = 1; $i < 50; $i++) {

      $buffer .= fgets($USB, 64);
      usleep(1800);  // 1800 ist ein guter Wert 30.3.2016

      if (substr(bin2hex($buffer),0,2) == "00") {
        $buffer = substr($buffer,1);
        continue;
      }

      if (substr(bin2hex($buffer),0,$Laenge) == $Befehl) {
        // Echo erhalten, Runde verlassen... (8 - 10 Runden)
        // echo "Echo erhalten: ".$i."\n";
        break;
      }

      // if (!empty($buffer))
      //  echo bin2hex($buffer)."\n";

    }

    if ($i >= 100) {
      // Kein Echo erhalten Fehler!
      // echo "Ausgang 1\n";
      return false;
    }

    $buffer = "";
    $i = 1;


    //  Hier werden erst die richtigen Daten erwartet.
    //
    for ($i = 1; $i < 200; $i++) {

      $buffer .= fgets($USB, 1024);
      usleep(1800);  // 1800 ist ein guter Wert 30.3.2016

      if (substr(bin2hex($buffer),0,2) == "00") {
        $buffer = substr($buffer,1);
        continue;
      }

      if (strlen($buffer) > 8 ) {
        $Laenge = hexdec(substr(bin2hex($buffer),4,4));
        if (strlen(bin2hex($buffer)) >= $Laenge*2) {
          // Die erwartete Datenlänge ist erreicht.
          // echo "Länge erreicht: ".$Laenge."  i:".$i."\n";
          break;
        }
      }

      // if (!empty($buffer))
      //   echo bin2hex($buffer)."\n";

    }

    if ($i >= 200) {
      // echo "Ausgang 2 - falsch\n";
      return false;
    }

    stream_set_blocking ( $USB , true );
      // echo "\nErgebnis: ".bin2hex($buffer);
    return bin2hex($buffer);
  }



  /******************************************************************
  //
  //  Auslesen des SolarMax S Serie Wechselrichter
  //
  ******************************************************************/
  function com_lesen($COM, $WR_Adresse, $Input="") {

    $sendenachricht = $this->nachricht_bauen($WR_Adresse, $Input);
    $rc = fwrite($COM, $sendenachricht);
    $haeder = explode(";",fread($COM,9));      // 9 Bytes lesen

    $laenge = hexdec($haeder[2])-9;
    $antwort = fread($COM, $laenge);
    $teile = explode("|",$antwort);

    if (empty($teile[1])) {
      return 0;
    }

    $befehl = substr($teile[1],(strpos($teile[1],":")+1));
    $ergebnis = explode("=",$befehl);

    if ($ergebnis[0] == $Input and substr($haeder[0],1) == sprintf("%02X",$WR_Adresse))  {
      // Der Befehl und die Adresse müssen übereinstimmen.
      return $ergebnis[1];
    }
    return 0;
  }



  function nachricht_bauen($adr,$befehl)  {
    # Die Nachricht zusammenbauen
    $src = 'FB';
    $adr = sprintf('%02X', $adr);
    $len = '00';
    $cs = '0000';
    $msg = is_array($befehl) ? "64:" . implode(';', $befehl) : "64:" . $befehl;
    $len = strlen("{" . $src . ";" . $adr . ";" . $len . "|" . $msg . "|" . $cs . "}");
    $len = sprintf("%02X", $len);
    $cs = $this->checksum16($src . ";" . $adr . ";" . $len . "|" . $msg . "|");
    $cs = sprintf("%04X", $cs);
    return "{" . $src . ";" . $adr . ";" . $len . "|" . $msg . "|" . $cs . "}";
  }


  /******************************************************************
  // 
  //  Auslesen des Labornetzteil   JT-DPM8600
  //
  ******************************************************************/

  function ln_lesen($USB, $WR_Adresse, $Input="") {

    $Adresse = str_pad(substr($WR_Adresse,-2), 2, 0, STR_PAD_LEFT);
    stream_set_blocking($USB, false);  // Es wird auf keine Daten gewartet.

    fwrite($USB,":".$Adresse.$Input.",\r\n");

    for ($k = 0; $k < 30; $k++) {

      $Antwort = trim(fread($USB,1024));
      if (substr($Antwort,0,1) == ":" and substr($Antwort,-1) == ".")   {
        $Teil = explode("=",$Antwort);
        $Ergebnis = substr($Teil[1],0,-1);
        break;
      }
      usleep(200000);
    }
    stream_set_blocking($USB, true);  // Zurück setzen.
    return $Ergebnis;

  }

  /**************************************************************************
  //  SDM630
  //  $Input["DeviceID"]
  //  $Input["BefehlFunctionCode"]
  //  $Input["RegisterAddress"]
  //  $Input["RegisterCount"]
  **************************************************************************/
  function sdm_auslesen($USB,$Input) {

    stream_set_blocking ( $USB , false );

    $BefehlBin = hex2bin($Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"]);

    $BefehlBin = $BefehlBin.$this->crc16($BefehlBin);

    // Befehl in HEX!
    // echo "** ".$Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"]."\n";

    for ($k = 1; $k < 3; $k++) {
      $buffer = fgets($USB, 500);
      $buffer = "";
      fputs ($USB, $BefehlBin);
      usleep(50000);  // 0,05 Sekunden warten

      for ($i = 1; $i < 50; $i++) {

        $buffer .= fgets($USB, 100);
        usleep(100000);
        // echo $i." ".bin2hex($buffer)."\n";
        if (substr(bin2hex($buffer),0,2) == "00" and substr(bin2hex($buffer),-2) == "00") {
          // Mit Start und Stop Bit '00'
          $hex = bin2hex($buffer);
          $start = substr($hex,0,2);
          $address = substr($hex,2,2);
          $functioncode = substr($hex,4,2);
          $lenght = substr($hex,6,2);
          $data = substr($hex,8,$lenght*2);
          $stop = substr($hex,-2);
          break 2;
        }
        elseif (substr(bin2hex($buffer),0,2) == $Input["DeviceID"] and substr(bin2hex($buffer),2,2) == $Input["BefehlFunctionCode"]) {
          // ohne Start und Stop Bit
          $hex = bin2hex($buffer);
          $address = substr($hex,0,2);
          $functioncode = substr($hex,2,2);
          $lenght = substr($hex,4,2);
          $data = substr($hex,6,$lenght*2);
          break 2;
        }
        elseif(strlen($buffer) > 0) {
          break;
        }
      }
    }
    /*************************
    echo  $hex."\n";
    echo  $start."\n";
    echo  $address."\n";
    echo  $functioncode."\n";
    echo  $lenght."\n";
    echo  $data."\n";
    echo  $stop."\n";
    ************************/

    stream_set_blocking ( $USB , true );

    if ($address == $Input["DeviceID"] and  $functioncode ==$Input["BefehlFunctionCode"]) {
      return round($this->hex2float($data),1);
    }
    return false;
  }



  function eSmart3_auslesen($Device,$Befehl='') {

    $Antwort = "";
    $OK = false;

    stream_set_blocking($Device, false);  // Es wird auf keine Daten gewartet.

    fgets($Device,4092);

    $Dauer = 2;  // 4 Sekunden
    $rc = fwrite($Device,hex2bin($Befehl));
    usleep(500000);

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";

    do {

      $Antwort = fgets($Device,192);

      // echo bin2hex($Antwort)."\n";

      if (trim($Antwort) == "") {
        // echo "Nichts empfangen .. \n";
        usleep(100000);
      }
      else {
        $Ergebnis .= $Antwort;

        if (strtoupper(substr(bin2hex($Ergebnis),0,2)) == "AA") {
          $Datenlaenge = hexdec(substr(bin2hex($Ergebnis),10,2));
          if (strlen($Ergebnis) >= ($Datenlaenge + 7)) {
            // Paket ist komplett
            $Dauer = 0; // Ausgang vorbereiten..
            $OK = true;
          }

        }
      }


    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking($Device, true);

    if ($OK == true) {
      return bin2hex($Ergebnis);
    }
    return false;
  }


  function eSmart3_ergebnis_auswerten($Daten) {
    $Ergebnis = array();
    if (strtoupper(substr($Daten,0,2)) == "AA" ) {

      if (substr($Daten,8,2) == "00" ) {


        $Ergebnis["Ladestatus"] = hexdec(substr($Daten,18,2).substr($Daten,16,2));
        $Ergebnis["Solarspannung"] = (hexdec(substr($Daten,22,2).substr($Daten,20,2))/10);
        $Ergebnis["Batteriespannung"] = (hexdec(substr($Daten,26,2).substr($Daten,24,2))/10);
        $Ergebnis["Batterieladestrom"] = (hexdec(substr($Daten,30,2).substr($Daten,28,2))/10);
        $Ergebnis["Verbraucherspannung"] = (hexdec(substr($Daten,38,2).substr($Daten,36,2))/10);
        $Ergebnis["Verbraucherstrom"] = (hexdec(substr($Daten,42,2).substr($Daten,40,2))/10);
        $Ergebnis["Solarleistung"] = hexdec(substr($Daten,46,2).substr($Daten,44,2));
        $Ergebnis["Verbraucherleistung"] = hexdec(substr($Daten,50,2).substr($Daten,48,2));
        $Ergebnis["Batterietemperatur"] = hexdec(substr($Daten,54,2).substr($Daten,52,2));
        $Ergebnis["Temperatur"] = hexdec(substr($Daten,58,2).substr($Daten,56,2));
        $Ergebnis["SOC"] = hexdec(substr($Daten,62,2).substr($Daten,60,2));
        $Ergebnis["CO2"] = (hexdec(substr($Daten,70,2).substr($Daten,68,2).substr($Daten,66,2).substr($Daten,64,2))/10);
        $Ergebnis["ErrorCode"] = substr($Daten,74,2).substr($Daten,72,2);

        // aa0101030020 0000 0300 6101 1601 0700 0000 0000 0000 1300 0000 1900 1a00 6400 00005e00 0000  [a6]
        //              12   16   20   24   28   32   36   40   44   48   52   56   60   64       72

      }


      if (substr($Daten,8,2) == "01" ) {


        $Ergebnis["Batterietyp"] = hexdec(substr($Daten,22,2).substr($Daten,20,2));
        $Ergebnis["Bulk_Spannung"] = (hexdec(substr($Daten,30,2).substr($Daten,28,2))/10);
        $Ergebnis["Float_Spannung"] = (hexdec(substr($Daten,34,2).substr($Daten,32,2))/10);
        $Ergebnis["MaxLadestrom"] = (hexdec(substr($Daten,38,2).substr($Daten,36,2))/10);
        $Ergebnis["MaxEntladestrom"] = (hexdec(substr($Daten,42,2).substr($Daten,40,2))/10);
        $Ergebnis["Auslastung"] = hexdec(substr($Daten,54,2).substr($Daten,52,2));

        // aa0101030116 0000 0ba4 0100 0000 9200 8a00 9001 9001 9200 1e00 0000 [9c]
        //              12   16   20   24   28   32   36   40   44   48   52

      }


      if (substr($Daten,8,2) == "02" ) {


        $Ergebnis["WattstundenGesamtHeute"] = hexdec(substr($Daten,50,2).substr($Daten,48,2).substr($Daten,46,2).substr($Daten,44,2));
        $Ergebnis["WattstundenGesamtMonat"] = hexdec(substr($Daten,66,2).substr($Daten,64,2).substr($Daten,62,2).substr($Daten,60,2));
        $Ergebnis["WattstundenGesamt"] = hexdec(substr($Daten,82,2).substr($Daten,80,2).substr($Daten,78,2).substr($Daten,76,2));


        // aa0101030234 0000 40a5 0000 0000 0100 0000 0000 0000 0000 0000 0000 0000 0000 0000
        //              12   16   20   24   28   32   36   40   44   48   52   56   60   64

        //              0000 0000 0000 0000 0000 0000 0000 0000 0000 0a00 0100 4ca5  [39]
        //              68   72   76   80   84   88   92   96   100  104  108  112

      }


      if (substr($Daten,8,2) == "08" ) {


        $hex = substr($Daten,20,32);
        for ($i=0; $i < strlen($hex)-1; $i+=2){
          $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        $Ergebnis["Seriennummer"] = $string;

        $string = "";
        $hex = substr($Daten,52,8);
        for ($i=0; $i < strlen($hex)-1; $i+=2){
          $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        $Ergebnis["Firmware"] = $string;

        $string = "";
        $hex = substr($Daten,60,32);
        for ($i=0; $i < strlen($hex)-1; $i+=2){
          $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        $Ergebnis["Produkt"] = $string;


        // aa0101030820 0000 4044 3334303030303033323031373037303156332e3065536d6172743700ff


      }

      return $Ergebnis;
    }
    else {
      return false;
    }
  }

  /******************************************************************
  //   DMODBUS vom Delta Wechselrichter lesen.
  //
  //      "Start" => "02"
  //      "Byte1" => "05",
  //      "ID" => "07",
  //      "Byte2" => "02",
  //      "Command" => "0000",
  //      "CRC" => "0000",
  //      "Stop" => "03");
  ******************************************************************/
  function delta_lesen($USB,$Input) {

    stream_set_blocking ( $USB , false );


    $BefehlBin = hex2bin($Input["Start"].$Input["Byte1"].$Input["ID"].$Input["Byte2"].$Input["Command"].$Input["CRC"].$Input["Stop"]);
  
    // echo $Input["Start"].$Input["Byte1"].$Input["ID"].$Input["Byte2"].$Input["Command"].$Input["CRC"].$Input["Stop"]."\n";

    $Laenge = strlen($BefehlBin);

    for ($k = 1; $k < 2; $k++) {
      $buffer = "";
      fputs ($USB, $BefehlBin);
 

      for ($i = 1; $i < 20; $i++) {

        $buffer .= fgets($USB, 4096);

        //  echo $i."[ ".bin2hex($buffer)." ]\n";

        if (!empty($buffer)) {
          // echo $i."[ ".bin2hex($buffer)." ]\n";
          if ($buffer == $BefehlBin) {
            echo bin2hex($buffer)." Echo erhalten\n";
            $buffer = "";
          }
          if (bin2hex(substr($buffer,0,2)) == "0206" and bin2hex(substr($buffer,-1)) == "03") {
            // echo $i."[ ".bin2hex($buffer)." ]\n";
            $Ergebnis = bin2hex($buffer);
            if ($Input["ID"] == substr($Ergebnis,4,2)) {
              $Laenge = hexdec(substr($Ergebnis,6,2));
              // echo "Länge: ".$Laenge."\n";

            }
            stream_set_blocking ( $USB , true );
            return substr($Ergebnis,8,($Laenge*2));
          }
        }
        usleep(50000);
      }
    }

    stream_set_blocking ( $USB , true );
    return false;
  }


  function alpha_auslesen($Device,$Befehl='') {

    $Antwort = "";
    $OK = false;

    stream_set_blocking($Device, false);  // Es wird auf keine Daten gewartet.

    fgets($Device,4092);

    $Dauer = 2;  // 4 Sekunden

    //echo $Befehl."\n";
    $rc = fwrite($Device,hex2bin($Befehl));
    usleep(500000);

    $Timestamp = time();
    $Ergebnis = "";
    $Antwort = "";

    do {

      $Antwort = fgets($Device,2048);

      //echo bin2hex($Antwort)."\n";

      if (trim($Antwort) == "") {
        //echo "Nichts empfangen .. \n";
        usleep(100000);
      }
      else {
        $Ergebnis .= $Antwort;

        //echo "Ergebnis: ".bin2hex($Ergebnis)."\n";
        //echo "Länge_soll: ".(hexdec(substr(bin2hex($Ergebnis),4,2))+5)."\n";
        //echo "Länge_ist: ".strlen($Ergebnis)."\n";
        if (substr(bin2hex($Ergebnis),0,4) == "5503") {
          $Datenlaenge = hexdec(substr(bin2hex($Ergebnis),4,2));
          if (strlen($Ergebnis) == ($Datenlaenge + 5)) {
            // Paket ist komplett
            $Dauer = 0; // Ausgang vorbereiten..
            $OK = true;
          }

        }
        if (substr(bin2hex($Ergebnis),0,4) == "5583") {
            $Dauer = 0; // Ausgang vorbereiten..
            $OK = false;
        } 
      }


    } while (($Timestamp + $Dauer) > time());

    stream_set_blocking($Device, true);

    if ($OK == true) {
      return bin2hex($Ergebnis);
    }
    return false;
  }


  /**************************************************************************
  //  Phocos PH1800 und PV18 auslesen
  //  $Input["DeviceID"]
  //  $Input["BefehlFunctionCode"]
  //  $Input["RegisterAddress"]
  //  $Input["RegisterCount"]
  **************************************************************************/
  function phocos_pv18_auslesen($USB,$Input) {

    stream_set_blocking ( $USB , false );

    $BefehlBin = hex2bin($Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"]);

    $BefehlBin = $BefehlBin.$this->crc16($BefehlBin);
    $data = array();
    $data["ok"] = false;

    // Befehl in HEX!
    // echo $Input["DeviceID"].$Input["BefehlFunctionCode"].$Input["RegisterAddress"].$Input["RegisterCount"];
    for ($k = 1; $k < 3; $k++) {
      $buffer = fgets($USB, 500);
      $buffer = "";
      fputs ($USB, $BefehlBin);
      usleep(50000);  // 0,05 Sekunden warten

      for ($i = 1; $i < 10; $i++) {

        $buffer .= fgets($USB, 1024);
        usleep(100000);

        // echo $i." ".bin2hex($buffer)."\n";

        // echo bin2hex($this->crc16(substr($buffer,0,-2)))."\n";
        // echo bin2hex(substr($buffer,-2))."\n";

        if (bin2hex($this->crc16(substr($buffer,0,-2))) ==  bin2hex(substr($buffer,-2)) ) {
          $data["address"] = substr(bin2hex($buffer),0,2);
          $data["functioncode"] = substr(bin2hex($buffer),2,2);
          $data["lenght"] = substr(bin2hex($buffer),4,2);
          $data["data"] = substr(bin2hex($buffer),6,hexdec($data["lenght"])*2);
          $data["ok"] = true;
          // echo $data["data"]." ..\n";
          break 2;
        }
      }
      sleep(1);
    }


    stream_set_blocking ( $USB , true );

    if ($data["ok"] == true) {
      return $data;
    }
    return false;
  }




  /**************************************************************************
  //  HTTP POST Request
  //
  //
  **************************************************************************/

  function http_read($abfrage) {


    $ch = curl_init($abfrage["URL"]);

    $i = 1;
    do {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_HTTPHEADER, $abfrage["Header"]); 
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);                //timeout in second s
      curl_setopt($ch, CURLOPT_PORT, $abfrage["Port"]);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $abfrage["Data"]);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $result = curl_exec($ch);
      $rc_info = curl_getinfo ($ch);
      $Ausgabe = json_decode($result,true);

      if (curl_errno($ch)) {
        $this->log_schreiben("Curl Fehler! HTTP Daten nicht vom Gerät gelesen! No. ".curl_errno($ch),"   ",5);
      }
      elseif ($rc_info["http_code"] == 200 or $rc_info["http_code"] == 204) {
        break;
      }
      elseif(empty($Ausgabe["error"])) {
        $this->log_schreiben("HTTP Fehler -> nochmal versuchen.","   ",5);
        $i++;
        continue;
      }
      $this->log_schreiben("Daten nicht vom Gerät gelesen! => [ ".$Ausgabe["error"]." ]","   ",5);
      $this->log_schreiben("HTTP  => [ ".$abfrage['Query']." ]","   ",5);
      $this->log_schreiben("Daten => [ ".print_r($abfrage,1)." ]","   ",9);
      $this->log_schreiben("Daten nicht von dem Gerät gelesen! info: ".var_export($rc_info,1),"   ",9);
      $i++;
      sleep(1);
    } while ($i < 3);

    curl_close($ch);
    return $Ausgabe;

  }


function senec($Daten) {

  $teile = explode("_", $Daten); 


  switch ($teile[0]) {

    case 'u1':
      $Ergebnis = strtolower(substr($teile[1],2));
    break;
    case "i1":
      $Ergebnis = hexdec($teile[1]);
    break;
    case "u3":
      $Ergebnis = hexdec($teile[1]);
    break;
    case "u8":
      $Ergebnis = hexdec($teile[1]);
    break;
    case "fl":
      $Ergebnis = $this->_hex2float($teile[1]);
    break;

    default:
      $Ergebnis = 0;
    break;
  }


return $Ergebnis;

}


function _hex2float($num) {

  $binfinal = sprintf("%032b",hexdec($num));
  $sign = substr($binfinal, 0, 1);
  $exp = substr($binfinal, 1, 8);
  $mantissa = "1".substr($binfinal, 9);
  $mantissa = str_split($mantissa);
  $exp = bindec($exp)-127;
  $significand=0;
  for ($i = 0; $i < 24; $i++) {
    $significand += (1 / pow(2,$i))*$mantissa[$i];
  }
  return $significand * pow(2,$exp) * ($sign*-2+1);
}


  /**************************************************************************
  //  Log Eintrag in die Logdatei schreiben
  //  $LogMeldung = Die Meldung ISO Format
  //  $Loglevel=5   Loglevel 1-10   10 = Trace
  **************************************************************************/

  function log_schreiben($LogMeldung,$Titel="   ",$Loglevel=5,$UTF8=0){
    Global $Tracelevel, $Pfad;
    $LogDateiName = $Pfad."/../log/solaranzeige.log";
    if ($Loglevel <= $Tracelevel) {

      if($UTF8) {
         $LogMeldung = utf8_encode($LogMeldung);
      }

      if ($handle = fopen($LogDateiName, 'a')) {
        //  Schreibe in die geöffnete Datei.
        //  Bei einem Fehler bis zu 3 mal versuchen.
        for ($i=1;$i<4;$i++) {
          $rc = fwrite($handle,date("d.m. H:i:s")." ".substr($Titel,0,3)."-".$LogMeldung."\n");
          if ($rc) {
            break;
          }
          sleep(1);
        }

        fclose($handle);
      }

    }

    return true;
  }


  /**************************************************************************
  //  HEX / String  Umwandlungsroutinen
  //
  **************************************************************************/


  function hex2str($hex) {
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
  }


  function hexdecs($hex)
  {
    // ignore non hex characters
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);

    // converted decimal value:
    $dec = hexdec($hex);

    //   maximum decimal value based on length of hex + 1:
    //   number of bits in hex number is 8 bits for each 2 hex -> max = 2^n
    //   use 'pow(2,n)' since '1 << n' is only for integers and therefore limited to integer size.
    $max = pow(2, 4 * (strlen($hex) + (strlen($hex) % 2)));

    // complement = maximum - converted hex:
    $_dec = $max - $dec;

    // if dec value is larger than its complement we have a negative value (first bit is set)
    return $dec > $_dec ? -$_dec : $dec;
  }

  function d2b($dec, $n = 16) {
    return str_pad(decbin($dec), $n, "0", STR_PAD_LEFT);
  }


  /**************************************************************************
  //  unterschiedliche CRC Berechnungen
  //
  //
  **************************************************************************/

  function crc8($ptr) {

    static $CRC8_Lookup = array(
         0x00, 0x8F, 0x27, 0xA8,
         0x4E, 0xC1, 0x69, 0xE6,
         0x9C, 0x13, 0xBB, 0x34,
         0xD2, 0x5D, 0xF5, 0x7A  );

    $ptr = hex2bin($ptr);

    $crc_table = $CRC8_Lookup;
    $currentCrc = 0x55;
    for ($i = 0; $i < strlen($ptr); $i++) {
      $currentCrc ^=  ord($ptr[$i]);

      $currentCrc = (($currentCrc >> 4) ^  $crc_table[$currentCrc & 0x0F]);
      $currentCrc = (($currentCrc >> 4) ^  $crc_table[$currentCrc & 0x0F]);
    }

  return substr("00".dechex($currentCrc),-2);

  }



  /*****************************************************************************
  // D_Daten + D_ServiceCode = CRCData
  //
  *****************************************************************************/
  function crc8Data($ptr) {

    $ptr = hex2bin($ptr);

    $currentCrc = 0x55;
    for ($i = 0; $i < strlen($ptr); $i++) {

      $currentCrc = ($currentCrc + ord($ptr[$i]));

    }

    return substr("00".dechex($currentCrc),-2);

  }

  function crc16_steca($ptr) {

    static $CRC16_Lookup = array(
      0x0000, 0xACAC, 0xEC05, 0x40A9,
      0x6D57, 0xC1FB, 0x8152, 0x2DFE,
      0xDAAE, 0x7602, 0x36AB, 0x9A07,
      0xB7F9, 0x1B55, 0x5BFC, 0xF750 );

    $ptr = hex2bin($ptr);

    $crc_table = $CRC16_Lookup;
    $currentCrc = 0x5555;
    for ($i = 0; $i < strlen($ptr); $i++) {
      $currentCrc ^=  ord($ptr[$i]);

      $currentCrc = (($currentCrc >> 4) ^  $crc_table[$currentCrc & 0x000F]);
      $currentCrc = (($currentCrc >> 4) ^  $crc_table[$currentCrc & 0x000F]);
    }

      return substr("000".dechex($currentCrc),-4);
  }

  function checksum16 ($msg)  {
    $bytes = unpack("C*", $msg);
    $sum = 0;
    foreach($bytes as $b) {
      $sum += $b;
      $sum = $sum % pow(2,16);
    }
    return $sum;
  }


  function crc16($data)
  {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
    $crc ^=ord($data[$i]);
      for ($j = 8; $j !=0; $j--) {
        if (($crc & 0x0001) !=0)	{
          $crc >>= 1;
          $crc ^= 0xA001;
        }
        else {
	      $crc >>= 1;
        }
      }
    }
    $highCrc=floor($crc/256);
    $lowCrc=($crc-$highCrc*256);
    return chr($lowCrc).chr($highCrc);
  }

  function crc16_us2000($Input) {

    $Summe = 0x0;
    for ($i=0; $i < strlen($Input); $i++) {

      $Summe = $Summe + ord($Input[$i]);

    }

    return strtoupper(substr(dechex((~$Summe)+1),4));
  }


  function CRC16Normal( $buffer ) {
    $result = 0;
    if ( ( $length = strlen( $buffer ) ) > 0 ) {
      for ( $offset = 0; $offset < $length; $offset++ ) {
        $result ^= ( ord( $buffer[$offset] ) << 8 );
        for ( $bitwise = 0; $bitwise < 8; $bitwise++ ) {
          if ( ( $result <<= 1 ) & 0x10000 )
          $result ^= 0x1021;
          $result &= 0xFFFF;
        }
      }
    }
    return $result;
  }


  function Hex2String($hex){
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2){
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    trim($string);
    return $string;
  }


  function string2hex($string){
    $hex='';
    for ($i=0; $i < strlen($string); $i++){
      if (strlen(dechex(ord($string[$i]))) == 1)
        $hex .= "0".dechex(ord($string[$i]));
      else
      $hex .= dechex(ord($string[$i]));
    }
    return $hex;
  }

  function VE_CRC($Daten) {

    $Dezimal = 85; //  HEX 55
    $Daten = "0".$Daten;

    for ($i=0; $i < strlen($Daten)-1; $i+=2){
      $Dezimal = ($Dezimal -  hexdec($Daten[$i].$Daten[$i+1]));
    }

    $CRC = strtoupper(substr("0".dechex($Dezimal),-2));
    return $CRC;
  }


  function eSmart3_CRC($Daten) {

    for ($i=0; $i < strlen($Daten)-1; $i+=2){
       $Checksum = $Checksum  + hexdec($Daten[$i].$Daten[$i+1]);
    }

    return substr((dechex(0-$Checksum)),-2);
  }

  function hex2float($number) {
    $binfinal = sprintf("%032b",hexdec($number));
    $sign = substr($binfinal, 0, 1);
    $exp = substr($binfinal, 1, 8);
    $mantissa = "1".substr($binfinal, 9);
    $mantissa = str_split($mantissa);
    $exp = bindec($exp)-127;
    $significand=0;
    for ($i = 0; $i < 24; $i++) {
        $significand += (1 / pow(2,$i))*$mantissa[$i];
    }
    return $significand * pow(2,$exp) * ($sign*-2+1);
  }

  function onebytechecksum($string) {
    $zahl = 0;

    for ($i=0 ; $i < strlen($string) ; $i++) {

      $zahl = $zahl + ord($string[$i]);

      if ($zahl > 256) {
        $zahl = $zahl - 256;
      }
    
    }
    return $zahl;
  }

  function kelvinToCelsius( $temperature ) {

    if ( !is_numeric( $temperature ) ) {
      return false;
    }
    
    // return round((($temperature - 273.15) * 1.8) + 32, 1);
    return round(($temperature - 273.15), 1);
  }

  function crc16_arc($data) {
    $crc = 0x0000;      // Init
        $len = strlen($data);
        $i = 0;
        while ($len--) {
            $crc ^= $this->reversebyte(ord($data[$i++])) << 8;
            $crc &= 0xffff;
            for ($j = 0; $j < 8; $j++){
                $crc = ($crc & 0x8000) ? ($crc << 1) ^ 0x8005 : $crc << 1;
                $crc &= 0xffff;
            }
        }
        //$crc ^= 0x0000;   // Final XOR
        $crc = $this->reversebits($crc);
        $crc = dechex($crc);
        return str_pad($crc,4,"0",STR_PAD_LEFT);
    }

    function reversebyte($byte) {
        $ob = 0;
        $b = (1 << 7);
        for ($i = 0; $i <= 7; $i++) {
            if (($byte & $b) !== 0) {
                $ob |= (1 << $i);
            }
            $b >>= 1;
        }
        return $ob;
    }

    function reversebits($cc) {
        $ob = 0;
        $b = (1 << 15);
        for ($i = 0; $i <= 15; $i++) {
            if (($cc & $b) !== 0) {
                $ob |= (1 << $i);
            }
            $b >>= 1;
        }
        return $ob;
    } 





}

?>