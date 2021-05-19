#!/usr/bin/php -q
<?php

// For Platypus $argv contains:
// [0] - Absolute to the running script
// [1...n] - Absolute path to each dropped file
// var_dump($argv);
date_default_timezone_set('Asia/Jakarta');
define('NOW', microtime(true));

$init = parse_ini_file('./Split PSD.ini');

if (!isset($init['source']) || !isset($init['server'])) {
  die('[Error] File init uncomplete.');
}
if (!file_exists($init['source'])) {
  $tmp = $init['source'];
  die('[Error] Folder that contains PSD files is not accessible.');
}
if (!file_exists($init['server'])) {
  die('[Error] Server Phototheque is not accessible.');
}
if (!is_writable($init['server'])) {
    die('[Error] Server Phototheque is not permitted in access.');
}
if (file_exists('/Volumes/Phototheque-1')) {
  die('[Error] Multiple phototheque connected.');
}
if (!file_exists('./timestamp')) {
  $fp = fopen('./timestamp', 'w') or die('[Error] Can not create the timestamp file.');
  fwrite($fp, time());
  fclose($fp);
}
////////////////////////////////////////////////////////////////

$dropped = array_slice($argv, 1);
$last_sync = file_get_contents('./timestamp');
$success = 0;
$error = array();

// Find the files dropped onto the app icon
if (!empty($dropped)) {
  printf("[Processing]..." . PHP_EOL);
  foreach ($dropped as $item) {
    // check if drag files/folders are in the init defined source
    if (strpos($item, $init['source']) === 0) {
      if (is_dir($item)) {
        $files = ListFiles($item, "{psd,tif,tiff}");
        printf("[Scan] %d file(s) found in %f seconde(s)" . PHP_EOL, count($files), microtime(true) - NOW);
        if (!empty($files)) {
          foreach($files as $image) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE); 
            $mime = finfo_file($finfo, $image);
            $destination = str_replace($init['source'], $init['server'], $image);
            
            if ($mime == "image/vnd.adobe.photoshop") {
							$pathinfo = pathinfo($item);
              $destination = dirname($destination) . '/' . $pathinfo['filename'] . '.tif';
              CreateTiff($image, $destination, false);
            }
            else if ($mime == "image/tiff") {
              CreateTiff($image, $destination, true);
            }
          }
        }
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE); 
        $mime = finfo_file($finfo, $item);
        $destination = str_replace($init['source'], $init['server'], $item);
        
        if ($mime == "image/vnd.adobe.photoshop") {
					$pathinfo = pathinfo($item);
          $destination = dirname($destination) . '/' . $pathinfo['filename'] . '.tif';
          CreateTiff($item, $destination, false);
        }
        else if ($mime == "image/tiff") {
          CreateTiff($item, $destination, true);
        }
        else {
          printf("  ❌ «%s» is not a tif or psd file." . PHP_EOL, basename($item));
          array_push($error, "    [" . basename($item) . "] Not a supported format file.");
        }
      }
    } else {
      printf("  ❌ «%s» is not in the right place." . PHP_EOL, basename($item));
    }
  }
  // Print out the Summary + update the timestamp
  printf("[Summary]" . PHP_EOL);
  printf("  ➞ %d file(s) processed sucessfuly" . PHP_EOL, $success);
  if(count($error) > 0) {
    printf("  ➞ %d error(s) occured:" . PHP_EOL, count($error));
    foreach($error as $entry) {
      echo "    ❌ " . trim($entry) . PHP_EOL;
    }
  }
} else {
  printf('[Last Sync] %s' . PHP_EOL, date("l, j F Y @ H:i:s", $last_sync));
  printf("[Source] %s" . PHP_EOL, $init['source']);
  $files = ListFiles($init['source'], "{psd,tif,tiff}");
  printf("[Scan] %d file(s) found in %f seconde(s)" . PHP_EOL, count($files), microtime(true) - NOW);
  if (count($files) > 0) {
    printf("[Processing]..." . PHP_EOL);
    foreach ($files as $image) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE); 
      $mime = finfo_file($finfo, $image);
      $destination = str_replace($init['source'], $init['server'], $image);

      if (($mime == "image/vnd.adobe.photoshop") && (filemtime($image)  > $last_sync)) {
        $destination = dirname($destination) . '/' . basename($image, '.psd') . '.tif';
        CreateTiff($image, $destination, false);
      }
      else if ($mime == "image/tiff") {
        CreateTiff($image, $destination, true);
      }
    }
    
    // Print out the Summary + update the timestamp
    printf("[Summary]" . PHP_EOL);
    
    printf("  ➞ %d file(s) processed sucessfuly" . PHP_EOL, $success);
    if(count($error) > 0) {
      printf("  ➞ %d error(s) occured:" . PHP_EOL, count($error));
      foreach($error as $entry) {
        echo "    ❌ " . trim($entry) . PHP_EOL;
      }
    }
    printf("  ➞ Total time: %f seconde(s) @ %s" . PHP_EOL, microtime(true) - NOW, date('H:i:s'));
    // Update the timestamp:
    $fp = fopen('./timestamp', 'w') or die('[Error] Can not update the timestamp file.');
    fwrite($fp, time());
    fclose($fp);
  }
}

function ListFiles($directory, $extension) {
  $paths = glob($directory . '*', GLOB_MARK | GLOB_ONLYDIR | GLOB_NOSORT);
  $files = glob($directory . "*." . $extension, GLOB_BRACE);
  foreach ($paths as $path) {
    $files = array_merge($files, ListFiles($path, $extension));
  }
  return $files;
}


function CreateTiff($source, $destination, $delete) {
  global $success, $error;
  $start = microtime(true);
  
  exec('sips --getProperty all ' . escapeshellarg($source), $foo);
  $properties = array();
  foreach ($foo as $key => $value) {
    if($key == 0 ) { $properties['path'] = $value; }
    else {
      $tmp = explode(': ', $value);
      if(!empty($tmp[1])) {
        $properties[trim($tmp[0])] = $tmp[1];
      }
    }
  }

  if($properties["space"] != "CMYK" && $properties["space"] != "Gray") {
    printf("  ❌ «%s» has wrong color space." . PHP_EOL, basename($source));
    array_push($error, "    [" . basename($source) . "] Bad color space.");
  } else if ((int)$properties["dpiWidth"] < 300 || (int)$properties["dpiHeight"] < 300) {
    printf("  ❌ «%s» resolution is too low." . PHP_EOL, basename($source));
    array_push($error, "    [" . basename($source) . "] Too low resolution.");
  } else if($properties["bitsPerSample"] > 8) {
    printf("  ❌ «%s» is 16 Bits per channel image." . PHP_EOL, basename($source));
    array_push($error, "    [" . basename($source) . "] 16 Bits images.");
  } else if($properties["profile"] != "ISO Coated v2 300% (ECI)" && $properties["profile"] != "Dot Gain 15%") {
    printf("  ❌ «%s» has wrong ICC profile." . PHP_EOL, basename($source));
    array_push($error, "    [" . basename($source) . "] Wrong ICC Profile.");
  }
  else {
    if(!file_exists(dirname($destination))) {
      mkdir(dirname($destination), 0777, true);
    }
    exec("sips -s format tiff -s formatOptions lzw " . escapeshellarg($source) . " --out " . escapeshellarg($destination));
    printf("  ✅ «%s» in %f secondes" . PHP_EOL, basename($source), microtime(true) - $start);
    if ($delete) {
      unlink($source) or die('[Error] Can not delete the file.');
    }
    $success++;
  }
}
