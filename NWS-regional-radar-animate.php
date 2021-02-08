<?php
#--------------------------------------------------------------------------------
# Program:  NWS-regional-radar-animate.php  
# Purpose:  fetch regional radar images from aviationweather.gov and create/cache
#           an animated GIF of the images
#
# Author:  Ken True - saratoga-weather.org
#
# Version 1.00 - 06-Jan-2021 - initial release
# Version 1.01 - 12-Jan-2021 - fix issue with empty ?region= argument and last-modified 
# Version 1.02 - 25-Jan-2021 - add error messages on not_available graphic display
# Version 1.03 - 08-Feb-2021 - add cache busting headers to output
#
# Usage:
#   Change $NWSregion and $NWStype below to prefered settings.
#
#   run in your website as an image :
#   <img src="NWS-regional-radar-animate.php" alt="Regional Radar"/>
#
#   the script will also honor ?region=<region>&type=<radar-type> on the<br />
#   URL in the <img> statement.
#
# Output:
#  In the $cacheFileDir directory, the following files will be produced:
#    NWS-<region>-<type><N>.gif  - temporary files of each radar image
#    NWS-<region>-<type>-ani.gif - resulting animated GIF file
#    NWS-<region>-<type>.txt     - page cache for aviationweather.gov page for the radar
#    NWS-<region>-<type>-latest.txt - file with latest image processed from aviationweather.gov
#    NWS-<region>-<type>-log.txt  - results of last animated gif creation run.
#
#  if no GIF creation is warrented (cache time not expired, latest image processed is still
#    latest image), then the script just returns the last NWS-<region>-<type>-ani.gif file
#    as an image.
#
# Notes:
#   Most of the regional radars are (WxH) 680x680px.  
#   The 'us' (conus) is 980x732px
#   The 'not available' image is 650x382px
#
#   Fetching the 15 images takes about 4-6 seconds for generation of the animated GIF image.
#
#--------------------------------------------------------------------------------
# Settings:
#
$NWSregion = 'wmc'; // see below $validRegions entries for valid regions to use 
# Note: 2 letter old $NWSregion regions will be translated to 3 letter regions
#       or use the regions in $validRegions array keys below
#
# Select radar type:
$NWStype = 'rala';  // ='rala' for 'Reference at low altitude'
#                   // ='cref' for 'Composite Reflectivity'
#                   // ='tops-18' for 'Echo Tops - 18dbz'

$refetchSeconds = 300;  // look for new images every 5 minutes (300 seconds)
$cacheFileDir = './cache/'; // directory for cache files

# for Zulu to local times:
$ourTZ = 'America/Los_Angeles'; // Timezone for display
$timeFormat = 'M d h:ia T';  // Jan 05 09:58am PST

#--------------------------------------------------------------------------------
# Constants -- don't change these
$Version = "NWS-regional-radar-animate.php - V1.03 - 08-Feb-2021";

$imgURL = 'https://www.aviationweather.gov';
$queryURL = 'https://www.aviationweather.gov/radar/plot?region=%s&type=%s&date=';

// Original list of regional sites
//
// $SITE['NWSregion'] = 'sw'; // NOAA/NWS regional radar maps
// 'ak' = Alaska,
// 'nw' = Northwest, 'nr' = Northern Rockies, 'nm' = North Mississippi Valley, 
// 'nc' = Central Great Lakes,  'ne' = Northeast,
// 'hi' = Hawaii,
// 'sw' = Southwest, 'sr' = Southern Rockies, 'sc' = Southern Plains,
// 'sm' = South Mississippi Valley, 'se' = Southeast

$oldRegions = array(
# original regions -> new validRegions, not *quite* matching all the old coverages
 'ak' => 'ak',  //Alaska
 'nw' => 'lws',  //Northwest
 'nr' => 'cod',  //Northern Rockies
 'nm' => 'msp',  //North Mississippi Valley
 
 'nc' => 'dtw',  //Central Great Lakes
 'ne' => 'alb',  //Northeast

 'hi' => 'hi',  //Hawaii

 'sw' => 'las',  //Southwest
 'sr' => 'den',  //Southern Rockies
 'sc' => 'aus',  //Southern Plains

 'sm' => 'lit',  //South Mississippi Valley
 'se' => 'mgm',  //Southeast

);
// New list of regional sites at 
$validRegions = array(
# current regional images
  'us' => 'Contiguous US',
  'ak' => 'Alaskan sector',
  'hi' => 'Hawaiian sector',
  'carib' => 'Caribbean sector',
  'lws' => 'LWS: Lewiston, ID sector',
  'wmc' => 'WMC: Winnemucca, NV sector',
  'las' => 'LAS: Las Vegas, NV sector',
  'cod' => 'COD: Cody, WY sector',
  'den' => 'DEN: Denver, CO sector',
  'abq' => 'ABQ: Albuquerque, NM sector',
  'pir' => 'PIR: Pierre, SD sector',
  'ict' => 'ICT: Wichita, KS sector',
  'aus' => 'AUS: Austin, TX sector',
  'msp' => 'MSP: Minneapolis, MN sector',
  'dtw' => 'DTW: Detroit, MI sector',
  'evv' => 'EVV: Evansville, IN sector',
  'lit' => 'LIT: Little Rock, AR sector',
  'alb' => 'ALB: Albany, NY sector',
  'bwi' => 'BWI: Baltimore, MD sector',
  'clt' => 'CLT: Charlotte, NC sector',
  'mgm' => 'MGM: Mongomery, AL sector',
  'tpa' => 'TPA: Tampa, FL sector',
);

# The only offered types of radar composites at aviationweather.gov
$validTypes = array(
  'rala' => 'Reference at low altitude',
  'cref' => 'Composite Reflectivity',
  'tops-18' => 'Echo Tops - 18dbz'
);

# ---------- main code -----------------
if (isset($_REQUEST['sce']) && strtolower($_REQUEST['sce']) == 'view' ) {
#--self downloader --
   $filenameReal = __FILE__;
   $download_size = filesize($filenameReal);
   header('Pragma: public');
   header('Cache-Control: private');
   header('Cache-Control: no-cache, must-revalidate');
   header("Content-type: text/plain,charset=ISO-8859-1");
   header("Accept-Ranges: bytes");
   header("Content-Length: $download_size");
   header('Connection: close');
   readfile($filenameReal);
   exit;
}
$hasUrlFopenSet = ini_get('allow_url_fopen');
if(!$hasUrlFopenSet) {
	print "<h2>Warning: PHP does not have 'allow_url_fopen = on;' --<br/>image fetch by script is not possible.</h2>\n";
	print "<p>To fix, add the statement: <pre>allow_url_fopen = on;\n\n</pre>to your php.ini file to enable script operation.</p>\n";
	return;
}

$Status = "<!-- $Version -->\n";

date_default_timezone_set($ourTZ);

if(isset($_REQUEST['region']) and strlen($_REQUEST['region']) > 0) {$NWSregion = preg_replace('![^a-z]+!','',$_REQUEST['region']); }

if(isset($_REQUEST['type']) and strlen($_REQUEST['type']) > 0) {$NWStype = preg_replace('![^a-z|\-|0-9]+!','',$_REQUEST['type']); }

if(isset($oldRegions[$NWSregion])) {
	$Status .= "<!-- NWSregion = '$NWSregion' changed to ";
	$NWSregion = $oldRegions[$NWSregion];
	$Status .= "'$NWSregion' -->\n";
}

if(!isset($validRegions[$NWSregion])) {
	$Status .= "<!-- NWSregion='$NWSregion' is not valid .. exiting. -->\n";
	log_status('');
	exit(0);
}

if(!isset($validTypes[$NWStype])) {
	$Status .= "<!-- NWStype='$NWStype' is not valid .. exiting. -->\n";
	log_status('');
	exit(0);
}

if (isset($_REQUEST['cache']) and (strtolower($_REQUEST['cache']) == 'no') or
    isset($_REQUEST['force']) and (strtolower($_REQUEST['force']) == '1') ) {
  $forceRefresh = true;
} else {
  $forceRefresh = false;
}

$mainURL = sprintf($queryURL,$NWSregion,$NWStype);
$Status .= "<!-- using '$mainURL' for query -->\n";

$RealCacheName = $cacheFileDir . 'NWSregion-'.$NWSregion.'-'.$NWStype.'.txt';
$latestTimeFile  = $cacheFileDir . 'NWSregion-'.$NWSregion.'-'.$NWStype.'-latest.txt';
$logFile = $cacheFileDir . 'NWSregion-'.$NWSregion.'-'.$NWStype.'-log.txt';
$output = $cacheFileDir . 'NWS-'.$NWSregion.'-'.$NWStype.'-ani.gif';

if(file_exists($RealCacheName)) {
	$lastCacheTime = filemtime($RealCacheName);
} else {
	$lastCacheTime = time();
	$forceRefresh = true;
}

$lastCacheTimeHM = gmdate("Y-m-d H:i:s",$lastCacheTime) . " UTC";
$expiresTime     = gmdate("Y-m-d H:i:s",$lastCacheTime+$refetchSeconds) . " UTC";
$NOWgmtHM        = gmdate("Y-m-d H:i:s",time()) . " UTC";
$diffSecs = time() - $lastCacheTime; 
$Status .= "<!-- now='$NOWgmtHM' page cached='$lastCacheTimeHM' ($diffSecs seconds ago) -->\n";	
if(isset($_GET['force']) | isset($_GET['cache'])) {$refetchSeconds = 0;}

if($diffSecs > $refetchSeconds) {$forceRefresh = true;}

$Status .= "<!-- forceRefresh=";
$Status .= $forceRefresh?'true':'false';
$Status .= " -->\n";

if (! $forceRefresh) {
      $Status .= "<!-- using Cached version from $RealCacheName -->\n";
      $site = implode('', file($RealCacheName));
    } else {
      $Status .= "<!-- loading $RealCacheName from\n  '$mainURL' -->\n";
      $site = NWSRA_fetchUrlWithoutHang($mainURL,false);
      $fp = fopen($RealCacheName, "w");
	  if (strlen($site) > 100 and $fp) {
        $write = fputs($fp, $site);
        fclose($fp);  
        $Status .= "<!-- loading finished. New page cache saved to $RealCacheName ".strlen($site)." bytes -->\n";
	  } else {
        $Status .= "<!-- unable to open $RealCacheName for writing ".strlen($site)." bytes.. cache not saved -->\n";
	  }
		$Status .= "<!-- html loading finished -->\n";
}

if(!file_exists($RealCacheName)) {
	  $Status .= "--Sorry.  Unable to write $RealCacheName to '$cacheDir'.\n";
	  print "  Make '$cacheDir' writable by PHP for this script to operate properly.\n";
		log_status('');
	  exit;
}

$latestImage = '';
$NWSimagesList = array();
$latestLegendFile = '';

# find the latest image 
if(preg_match('!id="img".*src="([^"]+)"!Uis',$site,$matches)) {
	
  #$Status .= "<!-- latest image \n".var_export($matches,true)." -->\n";
  $latestImage = $matches[1];
  $Status .= "<!-- latest image '$latestImage' -->\n";
}

# find the legend image
if(preg_match('!<img src="([^"]+)"[^>]+alt="Radar [^"]* legend"\s*/>!Us',$site,$matches)) {
	#$Status .= "<!-- legend matches\n".var_export($matches,true)." -->\n";
	$latestLegendFile = $matches[1];
	$Status .= "<!-- latest legend '$latestLegendFile' -->\n";
}

# find the list of radar images available (oldest to newest)
if(preg_match_all('!image_url\[(\d+)\]\s*=\s*"([^"]+)"!Uis',$site,$matches)) {
# image_url[0] = "/data/obs/radar/20210103/22/20210103_2220_rad_rala_wmc.gif";
  $NWSimagesList = $matches[2];	
#  $Status .= "<!-- images match \n".var_export($matches,true)." -->\n";
  $Status .= "<!-- NWSimagesList \n".var_export($NWSimagesList,true)." -->\n";
}

# see if our last generation GIF is still current
if(file_exists($latestTimeFile) and file_exists($output) and ! $forceRefresh) {
	$savedFileTime = file_get_contents($latestTimeFile);
	if($latestImage == $savedFileTime) { //use cached version
	   header('Content-type: image/gif');
		 header('Last-modified: '.gmdate('r',NWSRA_get_time($savedFileTime)) );
		 header('Expires: '.gmdate('r',NWSRA_get_time($savedFileTime)+$refetchSeconds) );
		 header('Cache-Control: public, max-age='.$refetchSeconds);
		 readfile($output);
		 exit(0);
	}
}

# sigh.. heavy lifting time.  Get the legend and all the GIF images fully annotated
$start_time = time();
if($forceRefresh) {
	if(strlen($latestLegendFile) > 0) {
		$legendImg = NWSRA_get_legend($imgURL . $latestLegendFile);
	} else {
		$legendImg = false;
	}
	foreach($NWSimagesList as $i => $img) {
		$toLoad = $imgURL . $img;
		$toSave = $cacheFileDir . 'NWS-'.$NWSregion.'-'.$NWStype.$i.'.gif';
		NWSRA_download_image($toLoad,$toSave,$i+1,count($NWSimagesList),$legendImg);
	}
	file_put_contents($latestTimeFile,$latestImage);
}

# Setup the animation control
    
  $frames = array();
  $framed = array();
  $delay = 50;
	$numimages = count($NWSimagesList); # count($NWSimagesList);
	
  for ($i=0;$i<$numimages;$i++) {
		$imgFile = $cacheFileDir . 'NWS-'.$NWSregion.'-'.$NWStype.$i.'.gif';
		if(file_exists($imgFile) and filesize($imgFile) > 100) {	
	    $frames[] = file_get_contents($imgFile);
	    $framed[] = $delay;
		}
  }
  $framed[$numimages-1] = $delay*3; # pause 3x at last frame
  
	#  $Status .= "<!-- frames\n".var_export($frames,true)." -->\n";
	/*
	foreach ($frames as $i => $fname) {
		if(file_exists($fname)) {
			$tfile = 'true';
			$tsize = filesize($fname);
		} else {
			$tfile = 'FALSE';
			$tsize = 'n/a';
		}
		$Status .= "<!-- cached file '$fname' exists=$tfile size=$tsize -->\n";
	}
	*/	
	# $Status .= "<!-- framed\n".var_export($framed,true)." -->\n";
  /*
		  GIFEncoder constructor:
		  =======================
  
		  image_stream = new GIFEncoder    (
							  URL or Binary data    'Sources'
							  int                    'Delay times'
							  int                    'Animation loops'
							  int                    'Disposal'
							  int                    'Transparent red, green, blue colors'
							  int                    'Source type'
						  );
  */
	
  $gif = new GIFEncoder (
							  $frames,
							  $framed,
							  0,
							  2,
							  0, 0, 0,
							  "bin"
		  );
  /*
		  Possibles outputs:
		  ==================
  
		  Output as GIF for browsers :
			  - Header ( 'Content-type:image/gif' );
		  Output as GIF for browsers with filename:
			  - Header ( 'Content-disposition:Attachment;filename=myanimation.gif');
		  Output as file to store into a specified file:
			  - FWrite ( FOpen ( "myanimation.gif", "wb" ), $gif->GetAnimation ( ) );
  */
  //Header ( 'Content-type:image/gif' );
  //echo    $gif->GetAnimation ( );
  
  $fh = fopen($output, "wb");
  fwrite($fh, $gif->GetAnimation ( ));
  fclose($fh);
  $Status .= "<!-- Animated GIF saved to $output. -->\n";

  $end_time = time();
  $elapsed = $end_time - $start_time;
  $Status .= "<!-- Animated GIF processing completed for $output in $elapsed seconds. -->\n\n";  

  header('Content-type: image/gif');
	header('Last-modified: '.gmdate('r',NWSRA_get_time($latestImage)) );
	header('Expires: '.gmdate('r',NWSRA_get_time($latestImage)+$refetchSeconds) );
	header('Cache-Control: public, max-age='.$refetchSeconds);
	readfile($output);

  log_status($logFile);

# end of main program

#-----------------------------------------------------------------------
#  Functions
#-----------------------------------------------------------------------

function NWSRA_get_time($nwsImageFile) {
	global $Status;
#	/data/obs/radar/20210112/16/20210112_1638_rad_rala_wmc.gif
	$tfile = pathinfo($nwsImageFile,PATHINFO_FILENAME);
	$t = explode('_',$tfile);
	if(isset($t[1]) and is_numeric($t[1])) {
		$lastUpdate = $t[0].'T'.$t[1].'00Z';
		$Status .= "<!-- last image time '$lastUpdate' -->\n";
		return strtotime($lastUpdate);
	} else {
		return time();
	}
	
}

#-----------------------------------------------------------------------

function log_status ( $fileName ) {
	global $Status;
	
	$Status = str_replace('<!-- ','',$Status);
	$Status = str_replace(' -->','',$Status);
	if(strlen($fileName) > 0) {
    file_put_contents($fileName,$Status);
	} else {
		print "<pre>\n";
		print $Status;
		print "</pre>\n";
	}
	
}

# -------------------------------------------------------------------

function NWSRA_get_legend($file_source) {
# get the legend image and return a $img file for later use
	global $Status,$timeFormat;

  $opts = array(
    'http'=>array(
    'method'=>"GET",
    'protocol_version' => 1.1,
    'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
            "Cache-control: max-age=0\r\n" .
            "Connection: close\r\n" .
            "User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php saratoga-weather.org)\r\n"
    ),
    'https'=>array(
    'method'=>"GET",
    'protocol_version' => 1.1,
    'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
            "Cache-control: max-age=0\r\n" .
            "Connection: close\r\n" .
            "User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php saratoga-weather.org)\r\n"
		)
  );

  $context = stream_context_create($opts);
  $imgBinary = file_get_contents($file_source,0,$context);
	$Timg= imagecreatefromstring($imgBinary);
	$img_width= imagesx($Timg);
	$img_height= imagesy($Timg);
	$img = imagecreatetruecolor($img_width,$img_height);
	imagecopy($img,$Timg,0,0,0,0,$img_width,$img_height);
	imagedestroy($Timg);
	
	$Status .= "<!-- Generating legend from $file_source w=$img_width h=$img_height.\n";
  return ($img);
}


function NWSRA_download_image($file_source, $file_target, $imgNum, $imgCnt, $legendImg) {
# fetch a GIF radar image, add local timestamp, credit and legend to image and
# save the temporary file for use by the animation routine.

  global $Status,$timeFormat,$NWSregion,$NWStype,$validRegions,$validTypes;

  $opts = array(
    'http'=>array(
    'method'=>"GET",
    'protocol_version' => 1.1,
    'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
            "Cache-control: max-age=0\r\n" .
            "Connection: close\r\n" .
            "User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php saratoga-weather.org)\r\n"
    ),
    'https'=>array(
    'method'=>"GET",
    'protocol_version' => 1.1,
    'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
            "Cache-control: max-age=0\r\n" .
            "Connection: close\r\n" .
            "User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php saratoga-weather.org)\r\n"
		)
  );

  $context = stream_context_create($opts);
  $imgBinary = file_get_contents($file_source,0,$context);
	
	if(preg_match('!(\d{8}_\d{4})!',$file_source,$m)) {
		$tstamp = str_replace('_','T',$m[1].'00 GMT');
		$lcltime = date($timeFormat,strtotime($tstamp));
	} else {
		$lcltime = ' ';
	}
		
	# use truecolor image as base (better graphic fidelity to original image)
	$Timg= imagecreatefromstring($imgBinary);
	$img_width= imagesx($Timg);
	$img_height= imagesy($Timg);
	$img = imagecreatetruecolor($img_width,$img_height);
	imagecopy($img,$Timg,0,0,0,0,$img_width,$img_height);
	imagedestroy($Timg);
	
	$Status .= "<!-- Generating image from $file_source w=$img_width h=$img_height.";
	$white = imagecolorallocate($img, 255, 255, 255);
	$light_gray = imagecolorallocate($img, 192, 192, 192);
	$blue = imagecolorallocate($img, 0, 0, 255);
	$black = imagecolorallocate($img, 0, 0, 0);
	$red = imagecolorallocate($img,255,0,0);
	$isAvailable = true;
	if(strpos($file_source,'not_available') !==false) {
		$isAvailable = false;
		# write what is not available on image
		$x = $img_width / 2;
		$y = 7; # $img_height - ($img_height / 4);
		$str = "region='$NWSregion' (".$validRegions[$NWSregion].") type='$NWStype' (".$validTypes[$NWStype].")";
		imagecenteredtext($img, $x, $y, $str, 3, $red);
		/* // looks like they started producing maps for ak, hi, carib on 26-Jan-2021
		if( in_array($NWSregion,array('ak','hi','carib')) ) {
			$y = $y+12;
			$str = "Note: radar may not be provided for region=$NWSregion (".$validRegions[$NWSregion].").";
		  imagecenteredtext($img, $x, $y, $str, 3, $red);
		}
		*/
		if($NWStype !== 'rala') {
			$y = $y+12;
			$str = "Try changing to type='rala' (".$validTypes['rala'].") for an available map.";
		  imagecenteredtext($img, $x, $y, $str, 3, $red);
		}
	}
  if($isAvailable) {
		# add the progress bar
		$barLen = 100; // pixels for progress bar
		$barHeight = 10; // height of the progress bar
		$tcnt = ($imgNum<10)?" $imgNum":"$imgNum";
		$seqNum = "$imgNum / $imgCnt";
		$yC = imagefontheight(3)+ 5;
		$xC = ($img_width / 2);
		$xBar = $xC-($barLen/2);
		$yBar = $yC + (imagefontheight(2)/2)-2;
		imagepolygon ($img, 
		array ($xBar-1, $yBar-1, 
				 $xBar+$barLen+1, $yBar-1,
				 $xBar+$barLen+1, $yBar+$barHeight+1,
				 $xBar-1, $yBar+$barHeight+1),
				 4, $black);
		$xLen = round($barLen*$imgNum/$imgCnt,0);
		
		imagefilledpolygon ($img, 
		array ($xBar, $yBar,
		$xBar+$xLen, $yBar,
		$xBar+$xLen, $yBar+$barHeight,
		$xBar, $yBar+$barHeight),
		4, $light_gray);
	
	
		$y = imagefontheight(3)+ 8;
		#$x = ($img_width / 2) + $barLen/2 + 4;
		$x = ($img_width / 2) - 13;
		imagestring($img, 2, $x, $y, $seqNum, $black);
		# end progress bar
	}
  # add the credit info
	$y = -2;
	$x = $img_width - 260;
	imagestring($img, 2, $x, $y, $lcltime, $blue);
	$creditText = 'GIF animation script by saratoga-weather.org';
	$y = $img_height - 20;
	$x = $img_width - 3 - strlen($creditText)*imagefontwidth(2); // left align to image
	imagestring($img, 2, $x, $y, $creditText,$blue);
		
	# add legend graphic to image
	if($legendImg !== false) {
		$from_width = imagesx($legendImg);
		$from_height = imagesy($legendImg);
		$toX = 1;
		$toY = $img_height - $from_height -1 ;
		 
		imagecopyresampled($img,$legendImg,
		$toX,$toY,   # $dst_x, $dst_y
		0,0,         # $src_x, $src_y
		$from_width,$from_height,  # $dst_w, $dst_h
		$from_width,$from_height );# $src_w, $src_h
	}

  # save the GIF to disk
	$result = imagegif($img,$file_target);
	if($result) {
		$Status .= "  Saved to $file_target -->\n";
	} else {
		$Status .= "  Unable to save to $file_target -->\n";
	}
	imagedestroy($img);
  return $result;
}

# -------------------------------------------------------------------

function NWSRA_fetchUrlWithoutHang($url,$useFopen) {
# get contents from one URL and return as string 
  global $Status, $needCookie;
  
  $overall_start = time();
  if (! $useFopen) {
   # Set maximum number of seconds (can have floating-point) to wait for feed before displaying page without feed
   $numberOfSeconds=6;   

# Thanks to Curly from ricksturf.com for the cURL fetch functions

  $data = '';
  $domain = parse_url($url,PHP_URL_HOST);
  $theURL = str_replace('nocache','?'.$overall_start,$url);        // add cache-buster to URL if needed
  $Status .= "<!-- curl fetching '$theURL' -->\n";
  $ch = curl_init();                                           // initialize a cURL session
  curl_setopt($ch, CURLOPT_URL, $theURL);                         // connect to provided URL
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);                 // don't verify peer certificate
  curl_setopt($ch, CURLOPT_USERAGENT, 
    'Mozilla/5.0 (NWS-regional-radar-animate.php - saratoga-weather.org)');

  curl_setopt($ch,CURLOPT_HTTPHEADER,                          // request LD-JSON format
     array (
         "Accept: text/html,text/plain"
     ));

  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $numberOfSeconds);  //  connection timeout
  curl_setopt($ch, CURLOPT_TIMEOUT, $numberOfSeconds);         //  data timeout
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);              // return the data transfer
  curl_setopt($ch, CURLOPT_NOBODY, false);                     // set nobody
  curl_setopt($ch, CURLOPT_HEADER, true);                      // include header information
#  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);              // follow Location: redirect
#  curl_setopt($ch, CURLOPT_MAXREDIRS, 1);                      //   but only one time
  if (isset($needCookie[$domain])) {
    curl_setopt($ch, $needCookie[$domain]);                    // set the cookie for this request
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);             // and ignore prior cookies
    $Status .=  "<!-- cookie used '" . $needCookie[$domain] . "' for GET to $domain -->\n";
  }

  $data = curl_exec($ch);                                      // execute session

  if(curl_error($ch) <> '') {                                  // IF there is an error
   $Status .= "<!-- curl Error: ". curl_error($ch) ." -->\n";        //  display error notice
  }
	$cinfo = array();
  $cinfo = curl_getinfo($ch);                                  // get info on curl exec.
/*
curl info sample
Array
(
[url] => http://saratoga-weather.net/clientraw.txt
[content_type] => text/plain
[http_code] => 200
[header_size] => 266
[request_size] => 141
[filetime] => -1
[ssl_verify_result] => 0
[redirect_count] => 0
  [total_time] => 0.125
  [namelookup_time] => 0.016
  [connect_time] => 0.063
[pretransfer_time] => 0.063
[size_upload] => 0
[size_download] => 758
[speed_download] => 6064
[speed_upload] => 0
[download_content_length] => 758
[upload_content_length] => -1
  [starttransfer_time] => 0.125
[redirect_time] => 0
[redirect_url] =>
[primary_ip] => 74.208.149.102
[certinfo] => Array
(
)

[primary_port] => 80
[local_ip] => 192.168.1.104
[local_port] => 54156
)
*/
  //$Status .= "<!-- cinfo\n".print_r($cinfo,true)." -->\n";
  $Status .= "<!-- HTTP stats: " .
    " RC=".$cinfo['http_code'];
	if(isset($cinfo['primary_ip'])) {
    $Status .= " dest=".$cinfo['primary_ip'];
	}
	if(isset($cinfo['primary_port'])) { 
	  $Status .= " port=".$cinfo['primary_port'];
	}
	if(isset($cinfo['local_ip'])) {
	  $Status .= " (from sce=" . $cinfo['local_ip'] . ")";
	}
	$Status .= 
	"\n      Times:" .
    " dns=".sprintf("%01.3f",round($cinfo['namelookup_time'],3)).
    " conn=".sprintf("%01.3f",round($cinfo['connect_time'],3)).
    " pxfer=".sprintf("%01.3f",round($cinfo['pretransfer_time'],3));
	if($cinfo['total_time'] - $cinfo['pretransfer_time'] > 0.0000) {
	  $Status .=
	  " get=". sprintf("%01.3f",round($cinfo['total_time'] - $cinfo['pretransfer_time'],3));
	}
    $Status .= " total=".sprintf("%01.3f",round($cinfo['total_time'],3)) .
    " secs -->\n";

  //$Status .= "<!-- curl info\n".print_r($cinfo,true)." -->\n";
  curl_close($ch);                                              // close the cURL session
  //$Status .= "<!-- raw data\n".$data."\n -->\n"; 
  $i = strpos($data,"\r\n\r\n");
  $headers = substr($data,0,$i);
  $content = substr($data,$i+4);
  if($cinfo['http_code'] <> '200') {
    $Status .= "<!-- headers returned:\n".$headers."\n -->\n"; 
  }
  return $data;                                                 // return headers+contents

 } else {
//   print "<!-- using file_get_contents function -->\n";
   $STRopts = array(
	  'http'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  ),
	  'https'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (NWS-regional-radar-animate.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  )
	);
	
   $STRcontext = stream_context_create($STRopts);

   $T_start = NWSRA_fetch_microtime();
   $xml = file_get_contents($url,false,$STRcontext);
   $T_close = NWSRA_fetch_microtime();
   $headerarray = get_headers($url,0);
   $theaders = join("\r\n",$headerarray);
   $xml = $theaders . "\r\n\r\n" . $xml;

   $ms_total = sprintf("%01.3f",round($T_close - $T_start,3)); 
   $Status .= "<!-- file_get_contents() stats: total=$ms_total secs -->\n";
   $Status .= "<-- get_headers returns\n".$theaders."\n -->\n";
//   print " file() stats: total=$ms_total secs.\n";
   $overall_end = time();
   $overall_elapsed =   $overall_end - $overall_start;
   $Status .= "<!-- fetch function elapsed= $overall_elapsed secs. -->\n"; 
//   print "fetch function elapsed= $overall_elapsed secs.\n"; 
   return($xml);
 }

}    // end NWSRA_fetchUrlWithoutHang

# -------------------------------------------------------------------

function NWSRA_microtime_float()
{
   list($usec, $sec) = explode(" ", microtime());
   return ((float)$usec + (float)$sec);
}

# -------------------------------------------------------------------
// imagecenteredtext() : text centering function for image creation
// centers on provided x, y coordinates
// you must pass all parameters even if you aren't using them.
// $img = image to write upon
// $x = x coordinate where the text will be centered
// $y = y coordinate where the text will be centered
// $text = the text to be written
// $size = font size for built-in GD fonts (1,2,3,4, or 5)
// $color = color as defined in the allocate colors section below
function imagecenteredtext($img,$x, $y, $text, $size, $color) {
  // if FreeType is not supported OR $font_file is set to none
  // we'll use the GD default fonts
       $x -= (imagefontwidth($size) * strlen($text)) / 2;
       $y -= (imagefontheight($size)) / 2;
       imagestring($img, $size, $x, $y - 3, $text, $color);
} // end function imagecenteredtext
# -------------------------------------------------------------------

/*
:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::
::
::    GIFEncoder Version 2.0 by László Zsidi, http://gifs.hu
::
::    This class is a rewritten 'GifMerge.class.php' version.
::
::  Modification:
::   - Simplified and easy code,
::   - Ultra fast encoding,
::   - Built-in errors,
::   - Stable working
::
::
::    Updated at 2007. 02. 13. '00.05.AM'
::
::  Updated 03-May-2020 for PHP 7.4 - K. True - saratoga-weather.org
::
::  Try on-line GIFBuilder Form demo based on GIFEncoder.
::
::  http://gifs.hu/phpclasses/demos/GifBuilder/
::
:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::
*/

Class GIFEncoder {
    var $GIF = "GIF89a";        /* GIF header 6 bytes    */
    var $VER = "GIFEncoder V2.05";    /* Encoder version        */

    var $BUF = Array ( );
    var $LOP =  0;
    var $DIS =  2;
    var $COL = -1;
    var $IMG = -1;

    var $ERR = Array (
        'ERR00'=>"Does not support function for only one image!",
        'ERR01'=>"Source is not a GIF image!",
        'ERR02'=>"Unintelligible flag ",
        'ERR03'=>"Does not make animation from animated GIF source",
    );

    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFEncoder...
    ::
    */
    public function __construct (
                            $GIF_src, $GIF_dly, $GIF_lop, $GIF_dis,
                            $GIF_red, $GIF_grn, $GIF_blu, $GIF_mod
                        ) {
        if ( ! is_array ( $GIF_src ) && ! is_array ( $GIF_tim ) ) {
            printf    ( "%s: %s", $this->VER, $this->ERR [ 'ERR00' ] );
            exit    ( 0 );
        }
        $this->LOP = ( $GIF_lop > -1 ) ? $GIF_lop : 0;
        $this->DIS = ( $GIF_dis > -1 ) ? ( ( $GIF_dis < 3 ) ? $GIF_dis : 3 ) : 2;
        $this->COL = ( $GIF_red > -1 && $GIF_grn > -1 && $GIF_blu > -1 ) ?
                        ( $GIF_red | ( $GIF_grn << 8 ) | ( $GIF_blu << 16 ) ) : -1;

        for ( $i = 0; $i < count ( $GIF_src ); $i++ ) {
            if ( strToLower ( $GIF_mod ) == "url" ) {
                $this->BUF [ ] = fread ( fopen ( $GIF_src [ $i ], "rb" ), filesize ( $GIF_src [ $i ] ) );
            }
            else if ( strToLower ( $GIF_mod ) == "bin" ) {
                $this->BUF [ ] = $GIF_src [ $i ];
            }
            else {
                printf    ( "%s: %s ( %s )!", $this->VER, $this->ERR [ 'ERR02' ], $GIF_mod );
                exit    ( 0 );
            }
            if ( substr ( $this->BUF [ $i ], 0, 6 ) != "GIF87a" && substr ( $this->BUF [ $i ], 0, 6 ) != "GIF89a" ) {
                printf    ( "%s: %d %s", $this->VER, $i, $this->ERR [ 'ERR01' ] );
                exit    ( 0 );
            }
            for ( $j = ( 13 + 3 * ( 2 << ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 ) ) ), $k = TRUE; $k; $j++ ) {
                switch ( $this->BUF [ $i ] [ $j ] ) {
                    case "!":
                        if ( ( substr ( $this->BUF [ $i ], ( $j + 3 ), 8 ) ) == "NETSCAPE" ) {
                            printf    ( "%s: %s ( %s source )!", $this->VER, $this->ERR [ 'ERR03' ], ( $i + 1 ) );
                            exit    ( 0 );
                        }
                        break;
                    case ";":
                        $k = FALSE;
                        break;
                }
            }
        }
        GIFEncoder::GIFAddHeader ( );
        for ( $i = 0; $i < count ( $this->BUF ); $i++ ) {
            GIFEncoder::GIFAddFrames ( $i, $GIF_dly [ $i ] );
        }
        GIFEncoder::GIFAddFooter ( );
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFAddHeader...
    ::
    */
    function GIFAddHeader ( ) {
        $cmap = 0;

        if ( ord ( $this->BUF [ 0 ] [ 10 ] ) & 0x80 ) {
            $cmap = 3 * ( 2 << ( ord ( $this->BUF [ 0 ] [ 10 ] ) & 0x07 ) );

            $this->GIF .= substr ( $this->BUF [ 0 ], 6, 7        );
            $this->GIF .= substr ( $this->BUF [ 0 ], 13, $cmap    );
            $this->GIF .= "!\377\13NETSCAPE2.0\3\1" . GIFEncoder::GIFWord ( $this->LOP ) . "\0";
        }
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFAddFrames...
    ::
    */
    function GIFAddFrames ( $i, $d ) {

        $Locals_str = 13 + 3 * ( 2 << ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 ) );

        $Locals_end = strlen ( $this->BUF [ $i ] ) - $Locals_str - 1;
        $Locals_tmp = substr ( $this->BUF [ $i ], $Locals_str, $Locals_end );

        $Global_len = 2 << ( ord ( $this->BUF [ 0  ] [ 10 ] ) & 0x07 );
        $Locals_len = 2 << ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 );

        $Global_rgb = substr ( $this->BUF [ 0  ], 13,
                            3 * ( 2 << ( ord ( $this->BUF [ 0  ] [ 10 ] ) & 0x07 ) ) );
        $Locals_rgb = substr ( $this->BUF [ $i ], 13,
                            3 * ( 2 << ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 ) ) );

        $Locals_ext = "!\xF9\x04" . chr ( ( $this->DIS << 2 ) + 0 ) .
                        chr ( ( $d >> 0 ) & 0xFF ) . chr ( ( $d >> 8 ) & 0xFF ) . "\x0\x0";

        if ( $this->COL > -1 && ord ( $this->BUF [ $i ] [ 10 ] ) & 0x80 ) {
            for ( $j = 0; $j < ( 2 << ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 ) ); $j++ ) {
                if    (
                        ord ( $Locals_rgb [ 3 * $j + 0 ] ) == ( ( $this->COL >> 16 ) & 0xFF ) &&
                        ord ( $Locals_rgb [ 3 * $j + 1 ] ) == ( ( $this->COL >>  8 ) & 0xFF ) &&
                        ord ( $Locals_rgb [ 3 * $j + 2 ] ) == ( ( $this->COL >>  0 ) & 0xFF )
                    ) {
                    $Locals_ext = "!\xF9\x04" . chr ( ( $this->DIS << 2 ) + 1 ) .
                                    chr ( ( $d >> 0 ) & 0xFF ) . chr ( ( $d >> 8 ) & 0xFF ) . chr ( $j ) . "\x0";
                    break;
                }
            }
        }
        switch ( $Locals_tmp [ 0 ] ) {
            case "!":
                $Locals_img = substr ( $Locals_tmp, 8, 10 );
                $Locals_tmp = substr ( $Locals_tmp, 18, strlen ( $Locals_tmp ) - 18 );
                break;
            case ",":
                $Locals_img = substr ( $Locals_tmp, 0, 10 );
                $Locals_tmp = substr ( $Locals_tmp, 10, strlen ( $Locals_tmp ) - 10 );
                break;
        }
        if ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x80 && $this->IMG > -1 ) {
            if ( $Global_len == $Locals_len ) {
                if ( GIFEncoder::GIFBlockCompare ( $Global_rgb, $Locals_rgb, $Global_len ) ) {
                    $this->GIF .= ( $Locals_ext . $Locals_img . $Locals_tmp );
                }
                else {
                    $byte  = ord ( $Locals_img [ 9 ] );
                    $byte |= 0x80;
                    $byte &= 0xF8;
                    $byte |= ( ord ( $this->BUF [ 0 ] [ 10 ] ) & 0x07 );
                    $Locals_img [ 9 ] = chr ( $byte );
                    $this->GIF .= ( $Locals_ext . $Locals_img . $Locals_rgb . $Locals_tmp );
                }
            }
            else {
                $byte  = ord ( $Locals_img [ 9 ] );
                $byte |= 0x80;
                $byte &= 0xF8;
                $byte |= ( ord ( $this->BUF [ $i ] [ 10 ] ) & 0x07 );
                $Locals_img [ 9 ] = chr ( $byte );
                $this->GIF .= ( $Locals_ext . $Locals_img . $Locals_rgb . $Locals_tmp );
            }
        }
        else {
            $this->GIF .= ( $Locals_ext . $Locals_img . $Locals_tmp );
        }
        $this->IMG  = 1;
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFAddFooter...
    ::
    */
    function GIFAddFooter ( ) {
        $this->GIF .= ";";
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFBlockCompare...
    ::
    */
    function GIFBlockCompare ( $GlobalBlock, $LocalBlock, $Len ) {

        for ( $i = 0; $i < $Len; $i++ ) {
            if    (
                    $GlobalBlock [ 3 * $i + 0 ] != $LocalBlock [ 3 * $i + 0 ] ||
                    $GlobalBlock [ 3 * $i + 1 ] != $LocalBlock [ 3 * $i + 1 ] ||
                    $GlobalBlock [ 3 * $i + 2 ] != $LocalBlock [ 3 * $i + 2 ]
                ) {
                    return ( 0 );
            }
        }

        return ( 1 );
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GIFWord...
    ::
    */
    function GIFWord ( $int ) {

        return ( chr ( $int & 0xFF ) . chr ( ( $int >> 8 ) & 0xFF ) );
    }
    /*
    :::::::::::::::::::::::::::::::::::::::::::::::::::
    ::
    ::    GetAnimation...
    ::
    */
    function GetAnimation ( ) {
        return ( $this->GIF );
    }
}

// end of functions
#-----------------------------------------------------------------------
