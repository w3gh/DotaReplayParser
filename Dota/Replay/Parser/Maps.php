<?php
/*
 * Dota_Replay_Parser_Maps Class file
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */


/**
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
class Dota_Replay_Parser_Maps {

  const XML_MAP_BASE_NAME = 'dota.allstars.v';

  const DEFAULT_XML_MAP = 'dota.allstars.v6.70.xml';

	/**
	 * Checks Map File Path, and if it exist, return it
	 *
	 * @static
	 * @throws Dota_Replay_Parser_Exception
	 * @param $mapname xml map file
	 * 
	 * @return string path to map
	 */
  public static function getMapFilePath($mapname)
  {
    $filename = dirname(__FILE__) . '/Maps/' . $mapname;

    if (!file_exists($filename)) {
        require_once 'Dota/Replay/Parser/Exception.php';
        throw new Dota_Replay_Parser_Exception("Missing map file '$mapname'.");
    }

    return $filename;
  }

  /**
   * Determine the Dota version and appropriate XML file
   * based on the map name extracted from the replay data
   *
   * @param mixed $mapName Map name extracted from the replay
   * @param mixed $dota_major number of Dota Major version
   * @param mixed $dota_minor number of Dota Minor version
   *
   * @return Returns the filename of the XML file to parse the replay with
   */
  public static function getMapFileName($mapName, &$dota_major, &$dota_minor)
  {
    $map_file = substr($mapName, strrpos($mapName, "\\") + 1);
    $map_file = substr($map_file, 0, -4);

    // Check if map version is valid
    preg_match("/([0-9]{1,1})\.([0-9]{1,2})([a-zA-Z]{0,1})/", $map_file, $matches);
    if (count($matches) < 4)
    {
      // Use default
      return DEFAULT_XML_MAP;
    }
    else
    {
      $dota_major = $matches[1];
      $dota_minor = $matches[2];
      $dota_subver = $matches[3];

      // Check if an appropriate .xml file with subversion exists ( ie. dota.allstars.v6.60b.xml )
      $mapsFolder = dirname(__FILE__) . '/Maps';
      if (file_exists($mapsFolder . "/" . self::XML_MAP_BASE_NAME . $dota_major . "." . $dota_minor . $dota_subver . ".xml"))
      {
        // Use it
        return self::XML_MAP_BASE_NAME . $dota_major . "." . $dota_minor . $dota_subver . ".xml";
      }
      // Check if an appropriate .xml file without subversion exists  ( ie. dota.allstars.v6.60.xml )
      else if (file_exists($mapsFolder . "/" . self::XML_MAP_BASE_NAME . $dota_major . "." . $dota_minor . ".xml"))
      {
        // Use it
        return self::XML_MAP_BASE_NAME . $dota_major . "." . $dota_minor . ".xml";
      }
      // If no file is found use the default, but only allow 6.59 or newer.
      else if ($dota_major < 6 || ( $dota_major == 6 && $dota_minor < 59 ))
      {
        require_once 'Dota/Replay/Parser/Exception.php';
        throw new Dota_Replay_Parser_Exception('Unsupported version of DotA');
      }
      else
      {
        // Use default
        return self::DEFAULT_XML_MAP;
      }
    }
  }

}

