<?php

/*
 * Dota_Replay_Parser Class file
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * @see Dota_Replay_Parser_Maps
 */
require_once 'Dota/Replay/Parser/Maps.php';

/**
 * @see Dota_Replay_Parser_Entity_Hero
 */
require_once 'Dota/Replay/Parser/Entity/Hero.php';

/**
 * @see Dota_Replay_Parser_Entity_Skill
 */
require_once 'Dota/Replay/Parser/Entity/Skill.php';

/**
 * @see Dota_Replay_Parser_Entity_Item
 */
require_once 'Dota/Replay/Parser/Entity/Item.php';



/**
 * Parse Xml Data for Replay format
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
class Dota_Replay_Parser
{

  public $Heroes;
  public $Skills;
  public $Items;
  public $info;   // Stores general Map info like version, etc.

  /**
   * Links ID's to the appropriate Object.
   * @var array
   */
  public $hashMap = array();
  
  /**
   * Links Skills to Heroes
   * @var array
   */
  public $skillToHeroMap;


  private $current = ''; // Tag being currently parsed
  private $prev = '';   // Previously parsed tag
  private $item = array();  // Array of "Item" data tags
  private $inItem = false; // Currently parsing an "Item"

  /**
   * @var resource XML
   */
  private $xmlparser;

  private $xmlmapfile;

  protected static $_instance = null;


  public function __construct()
  {

    if (!($this->xmlparser = xml_parser_create()))
    {
      require_once 'Dota/Replay/Parser/Exception.php';
      throw new Dota_Replay_Parser_Exception('Cannot create parser');
    }
    xml_set_object($this->xmlparser, $this);
    xml_set_element_handler($this->xmlparser, "startTag", "endTag");
    xml_set_character_data_handler($this->xmlparser, "tagContents");
  }

  /**
   * Singleton instance
   *
   * @return Dota_Replay_Parser
   */
  public static function getInstance()
  {
      if (null === self::$_instance) {
          self::$_instance = new self();
      }

      return self::$_instance;
  }

  public function getXmlParser()
  {
    return $this->xmlparser;
  }

  public function setMapName($mapName, &$dota_major, &$dota_minor)
  {
    $this->xmlmapfile=Dota_Replay_Parser_Maps::getMapFileName($mapName, &$dota_major, &$dota_minor);
    return $this;
  }

  /**
   * Parse xml dota map schema
   * @param string $filename
   */
  public function parseMap($map=null)
  {
    if(!is_null($this->xmlmapfile))
      $filenmame = Dota_Replay_Parser_Maps::getMapFilePath($this->xmlmapfile);
    elseif(!is_null($map))
      $filenmame = Dota_Replay_Parser_Maps::getMapFilePath($map);
    else
    {
      require_once 'Dota/Replay/Parser/Exception.php';
      throw new Dota_Replay_Parser_Exception(sprintf('Map is not set.'));
    }

    if (!($fp = fopen($filenmame, "r")))
    {
      require_once 'Dota/Replay/Parser/Exception.php';
      throw new Dota_Replay_Parser_Exception(sprintf('Cannot open %s', $filenmame));
    }

    $datas = "";
    while ($data = fread($fp, 4096))
    {
      // Remove all blankspaces
      $datas .= $data;
    }
    // $datas = eregi_replace(">"."[[:space:]]+"."<","><",$datas);
    // Parsing
    $parser = self::getInstance()->getXmlParser();

    if (!xml_parse($parser, $datas, feof($fp)))
    {
      $reason = xml_error_string(xml_get_error_code($parser));
      $reason .= xml_get_current_line_number($parser);
    }

    xml_parser_free($parser);
  }



  /**
   * Data handling
   * @param handle to our parser
   * @param data
   */
  function tagContents($parser, $data)
  {
    $data = trim($data);

    if ($data == "")
      return;

    if ($this->inItem)
    {
      $this->item[$this->current] = $data;
    }
    else
    {
      $this->info[$this->current] = $data;
    }
  }

  /**
   * Start tag parsing
   * @param handle to our parser
   * @param name of the current tag
   * @param an array containing any attributes of the current tag
   */
  function startTag($parser, $name, $attribs)
  {
    $this->current = $name;

    switch ($name)
    {
      // Reset the current item's data, start building it
      case 'ITEM':
        $this->item = array();
        $this->inItem = true;
        break;
      // Map info
      default:
        break;
    }

//    if (!empty($attribs) && is_array($attribs))
//    {
//      echo "Attributes : <br />";
//      while (list($key, $val) = each($attribs))
//      {
//        echo "Attribute " . $key . " has value " . $val . "<br />";
//      }
//    }
  }

  /**
   * End tag parsing
   * @param handle to our parser
   * @param name of the current tag
   */
  function endTag($parser, $name)
  {
    $this->prev = $name;

    if ($name == "ITEM" && isset($this->item['TYPE']))
    {
      $this->inItem = false;
      switch ($this->item['TYPE'])
      {

        case 'HERO':
          $tmp = new Dota_Replay_Parser_Entity_Hero($this->item['NAME'],
              (isset($this->item['ART']) ? $this->item['ART'] : ""),
              (isset($this->item['COMMENT']) ? $this->item['COMMENT'] : ""),
              (isset($this->item['COST']) ? $this->item['COST'] : ""),
              $this->item['ID'],
              (isset($this->item['PROPERNAMES']) ? $this->item['PROPERNAMES'] : ""),
              (isset($this->item['RELATEDTO']) ? $this->item['RELATEDTO'] : ""),
              $this->item['TYPE']);


          $split = $tmp->parseRelated();
          foreach ($split as $skill)
          {
            $this->skillToHeroMap[$skill] = $this->item['ID'];
          }
          break;
        case 'ITEM':
          $tmp = new Dota_Replay_Parser_Entity_Item($this->item['NAME'],
              (isset($this->item['ART']) ? $this->item['ART'] : ""),
              (isset($this->item['COMMENT']) ? $this->item['COMMENT'] : ""),
              (isset($this->item['COST']) ? $this->item['COST'] : ""),
              $this->item['ID'],
              (isset($this->item['PROPERNAMES']) ? $this->item['PROPERNAMES'] : ""),
              (isset($this->item['RELATEDTO']) ? $this->item['RELATEDTO'] : ""),
              $this->item['TYPE']);
          break;
        case 'ULTIMATE':
        case 'SKILL':
        case 'STAT':
          $tmp = new Dota_Replay_Parser_Entity_Skill($this->item['NAME'],
              (isset($this->item['ART']) ? $this->item['ART'] : ""),
              (isset($this->item['COMMENT']) ? $this->item['COMMENT'] : ""),
              (isset($this->item['COST']) ? $this->item['COST'] : ""),
              $this->item['ID'],
              (isset($this->item['PROPERNAMES']) ? $this->item['PROPERNAMES'] : ""),
              (isset($this->item['RELATEDTO']) ? $this->item['RELATEDTO'] : ""),
              $this->item['TYPE']);
          break;
      }

      // Add the Object to the HashMap
      if (isset($tmp))
      {
        $this->hashMap[$tmp->getId()] = $tmp;
      }
    }
  }

}

