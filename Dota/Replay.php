<?php

/*
 * Dota_Replay Class file
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * @see Dota_Replay_Parser
 */
require_once 'Dota/Replay/Parser.php';

/**
 * @see Dota_Replay_Parser_Modes_Cd
 */
require_once 'Dota/Replay/Parser/Modes/Cd.php';

/**
 * @see Dota_Replay_Parser_Modes_Cm
 */
require_once 'Dota/Replay/Parser/Modes/Cm.php';

/**
 * @see Dota_Replay_Parser_Exception
 */
require_once 'Dota/Replay/Parser/Exception.php';

/**
 * DotA Warcraft III w3g Replay Format Parser
 *
 * @author Nikolay Kostyurin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @version $Id:$
 * @package Dota_Replay
 */
class Dota_Replay
{

  protected $fp, $data, $leave_unknown, $continue_game, $referees, $time, $pause, $leaves, $errors, $header, $players;
    
  /**
   * Parse Actions or Not ?
   * @var bool
   */
  protected $_parseActions = true;

  /**
   * Parse Game chat or Not ?
   * @var bool
   */
  protected $_parseChat = true;

  /**
   * Collects all game information data
   * @var array
   */
  protected $game = array();

  /**
   * Collects all game chat if parse chat flag is TRUE
   * @var array
   */
  protected $chat = array();

  /**
   * To know when there is a need to load next block
   * @var int
   */
  protected $max_datablock;
    
  const MAX_DATABLOCK = 1500;

  /**
   * For preventing duplicated actions
   */
  const ACTION_DELAY = 1000;

  /**
   * Used for CM\CD Mode
   */
  protected $inPickMode = false;
  protected $bans, $picks;
  protected $picks_num, $bans_num;
  const NUM_OF_BANS = 8;
  const NUM_OF_PICKS = 10;


  /**
   * Info about observers
   * @var array
   */
  protected $observers = array();
  /**
   * Used for hero Swapping
   * @var array
   */
  protected $swapHeroes = array();
  /**
   * Maps Slot ID to Player ID
   * @var array
   */
  protected $slotToPlayerMap = array();
  /**
   * Statistics
   * @var array
   */
  protected $stats = array();
  /**
   * Holds extra information for replay displaying
   * @var array
   */
  protected $extra = array(),
  $previousPick,
  // Activated Heroes, used for tracking hero assignment.
  $activatedHeroes = array(),
  // Store heroes picked before 0:15 dota player IDs are sent out
  $preAnnouncePick = array(),
  // Store skills set before 0:15
  $preAnnounceSkill = array(),
  // Stores the mode, used for picking / banning so far
  $dotaMode;
  /**
   * Stores player names based on WC3 IDs
   * @var array
   */
  protected $wc3idToNames = array();
  /**
   * Stores player's WC3 IDs based on dota IDs
   * @var array
   */
  protected $dotaIdToWc3id = array();
  /**
   * Map 'game' Dota IDs to 'internal' Dota IDs
   * @var array
   */
  protected $translatedDotaID = array();
  /**
   * Valid callback for translate
   * @var callback
   */
  protected $_translateCallback;
  /**
   * Valid callback for log
   * @var callback
   */
  protected $_logCallback;
  /**
   * Options of Parse
   * @var array
   */
  protected $_options = array();
  /**
   * Flag for detecting parsed replay or not
   * @var bool
   */
  protected $_parsed = false;
  /**
   * @var Dota_Replay_Parser instance
   */
  protected static $_parser;

  /**
   * Export all parsed replay data into Array
   * if $this->run() not called, it calls itself
   * @return array
   */
  public function toArray()
  {
    if (!$this->_parsed)
      $this->run();

    if (!isset($this->game['creator'], $this->game['saver_name'], $this->game['player_count']))
      return array();

    if (isset($this->extra['title']))
      $export['title'] = $this->extra['title'];

    $export['host'] = $this->game['creator'];

    $export['saver'] = $this->game['saver_name'];

    $export['players_count'] = $this->game['player_count'];

    $export['version'] = '1.' . sprintf('%02d', $this->header['major_v']);
    $export['length'] = $this->_convertTime($this->header['length']);
    $export['observers_type'] = $this->game['observers'];
    $export['map'] = $this->game['map'];
    if (isset($this->extra['parsed_winner']))
      $export['winner'] = $this->extra['parsed_winner'];
    if (isset($this->extra['original_filename']))
      $export['original_filename'] = $this->extra['original_filename'];

    //Dota Bans
    if ($this->bans_num > 0)
    {
      foreach ($this->bans as $hero)
      {
        $team = ($hero->extra == 0 ? "Sentinel" : "Scourge");
        $team = $this->_t($team);

        $export['bans'][$team][] = array(
          'name' => $hero['name'],
          'image' => $hero['art'],
          'id' => $hero['id'],
          'proper_names' => $hero['properNames'],
        );
      }
    }

    //Dota Picks
    if ($this->picks_num > 0)
    {
      foreach ($this->picks as $hero)
      {
        $team = ($hero->extra == 0 ? "Sentinel" : "Scourge");
        $team = $this->_t($team);

        $export['picks'][$team][] = array(
          'name' => $hero['name'],
          'image' => $hero['art'],
          'id' => $hero['id'],
          'proper_names' => $hero['properNames'],
        );
      }
    }

    $export['teams']=array();
    //Teams
    for ($i = 0; $i < 2; $i++)
    {
      $team = ( $i == 0 ? "Sentinel" : "Scourge" );
      $team = $this->_t($team);

      //Players for $i team
      if (isset($this->teams[$i]))
      {
        foreach ($this->teams[$i] as $pid => $player)
        {

          // Convert 1.2 version to legacy (1.1) output
          if (isset($this->activatedHeroes))
          {
            if ($this->stats[$player['dota_id']]->getHero() == false)
              continue;

            $t_heroName = $this->stats[$player['dota_id']]->getHero()->getName();

            // Set level
            $player['heroes'][$t_heroName]['level'] = $this->stats[$player['dota_id']]->getHero()->getLevel();

            $t_heroSkills = $this->stats[$player['dota_id']]->getHero()->getSkills();

            // Convert skill array to old format
            foreach ($t_heroSkills as $time => $skill)
            {
              $time = $this->_convertTime($time);
              $player['heroes'][$t_heroName]['abilities'][$time] = $skill;
            }

            $player['heroes'][$t_heroName]['data'] = $this->stats[$player['dota_id']]->getHero()->getData();
          }


          // Get player's hero
          foreach ($player['heroes'] as $name => $hero)
          {

            if ($name == "order" || !isset($hero['level']))
              continue;

            if ($name != "Common")
            {
              // Merge common skills and atribute stats with Hero's skills
              if (isset($player['heroes']['Common']))
              {

                $hero['level'] += $player['heroes']['Common']['level'];
                $hero['abilities'] = array_merge($hero['abilities'], $player['heroes']['Common']['abilities']);
              }
              if ($hero['level'] > 25)
              {
                $hero['level'] = 25;
              }
              @ksort($hero['abilities']);
              $p_hero = $hero;
              if (!is_array($p_hero))
                return FALSE;
              break;
            }
          }

          $export['teams'][$team][$player['player_id']] = array(
            'hero' => array(
              'id' => $p_hero['data']['id'],
              'image' => $p_hero['data']['art'],
              'name' => $p_hero['data']['name'],
              'proper_names' => $p_hero['data']['properNames'],
              'level' => $p_hero['level'],
            ),
            'apm' => round((60 * 1000 * $player['apm']) / ($player['time'])),
            'id' => $player['player_id'],
            'initiator' => $player['initiator'],
            'name' => $player['name'],
            'computer' => $player['computer'],
            'team' => $player['team'],
            'color' => $player['color'],
            'race' => $player['race'],
            'ai_strength' => $player['ai_strength'],
            'handicap' => $player['handicap'],
            'action_details' => $player['actions_details'],
          );

          if (isset($this->stats[$player['dota_id']]))
          {
            $stats = $this->stats[$player['dota_id']];
            $export['teams'][$team][$player['player_id']]['kills'] = $stats->heroKills;
            $export['teams'][$team][$player['player_id']]['deaths'] = $stats->deaths;
            $export['teams'][$team][$player['player_id']]['assists'] = $stats->assists;
            $export['teams'][$team][$player['player_id']]['creep_kills'] = $stats->creepKills;
            $export['teams'][$team][$player['player_id']]['creep_denies'] = $stats->creepDenies;
            $export['teams'][$team][$player['player_id']]['neutral_kills'] = $stats->neutrals;

            for ($j = 0; $j < 6; $j++)
            {
              $is_item = ( isset($stats->inventory[$j]) && is_object($stats->inventory[$j]) );
              $art = ($is_item) ? $stats->inventory[$j]['art'] : "images/BTNEmpty.gif";
              $name = ($is_item) ? $stats->inventory[$j]['name'] : "Empty";
              $cost = ($is_item) ? $stats->inventory[$j]['cost'] : 0;
              $id = ($is_item) ? $stats->inventory[$j]['id'] : '';
              $export['teams'][$team][$player['player_id']]['hero']['inventory'][$j] = array(
                'image' => $art,
                'name' => $name,
                'cost' => $cost,
                'id' => $id,
              );
            }
          }

          // Handle player left events
          if (isset($player['time']))
          {
            $playerLeaveTime = $this->_convertTime($player['time']);
          }
          else
          {
            $playerLeaveTime = $this->_convertTime($this->header['length']);
          }
          if (isset($player['leave_result']))
          {
            $leaveResult = $player['leave_result'];
          }
          else
          {
            $leaveResult = $this->_t("Finished");
          }

          $export['teams'][$team][$player['player_id']]['leave_time'] = $playerLeaveTime;
          $export['teams'][$team][$player['player_id']]['leave_reason'] = $leaveResult;

          //POTM Arrow
          if (isset($this->stats[$player['dota_id']]->AA_Total) && isset($this->stats[$player['dota_id']]->AA_Hits) && $this->stats[$player['dota_id']]->AA_Total > 0)
          {
            $export['teams'][$team][$player['player_id']]['hero']['aa_percent'] = round((($this->stats[$player['dota_id']]->AA_Hits / $this->stats[$player['dota_id']]->AA_Total) * 100));
            $export['teams'][$team][$player['player_id']]['hero']['aa_hits'] = $this->stats[$player['dota_id']]->AA_Hits;
            $export['teams'][$team][$player['player_id']]['hero']['aa_total'] = $this->stats[$player['dota_id']]->AA_Total;
          }

          //Pudge Hook
          if (isset($this->stats[$player['dota_id']]->HA_Total) && isset($this->stats[$player['dota_id']]->HA_Hits) && $this->stats[$player['dota_id']]->HA_Total > 0)
          {
            $export['teams'][$team][$player['player_id']]['hero']['ha_percent'] = round((($this->stats[$player['dota_id']]->HA_Hits / $this->stats[$player['dota_id']]->HA_Total) * 100));
            $export['teams'][$team][$player['player_id']]['hero']['ha_hits'] = $this->stats[$player['dota_id']]->HA_Hits;
            $export['teams'][$team][$player['player_id']]['hero']['ha_total'] = $this->stats[$player['dota_id']]->HA_Total;
          }

          //Skills
          $i_skill = 0;
          unset($a_level);
          if (isset($p_hero['abilities']))
          {
            foreach ($p_hero['abilities'] as $time => $ability)
            {
              $i_skill++;
              if ($i_skill > 25)
                break;

              if (!isset($a_level[$ability->getName()]))
              {
                $a_level[$ability->getName()] = 1;
              }
              else
              {
                $a_level[$ability->getName()]++;
              }
              $export['teams'][$team][$player['player_id']]['hero']['skills'][$i_skill] = array(
                'image' => $ability['art'],
                'time' => $time,
                'name' => $ability['name'] . ' ' . $a_level[$ability['name']]
              );
            }
          }

          //buy items
          if (is_array($player['items']))
          {
            foreach ($player['items'] as $time => $item)
            {
              if (is_object($item) && $item->getName() != "Select Hero")
              {
                $export['teams'][$team][$player['player_id']]['hero']['items'][$time] = array(
                  'id' => $item['id'],
                  'image' => $item['art'],
                  'name' => $item['name'],
                  'cost' => $item['cost'],
                );
              }
            }
          }

          // Remember colors for Chat display.
          if ($player['color'] && $player['player_id'] && $player['name'])
          {
            $export['colors'][$player['player_id']] = $player['color'];
            $export['colornames'][$player['name']] = $player['color'];
            $export['names'][$player['player_id']] = $player['name'];
          }

          if ($player['name'] && $p_hero)
          {
            $export['playersheroes'][$player['name']] = array(
              'id' => $p_hero['data']['id'],
              'image' => $p_hero['data']['art'],
              'name' => $p_hero['data']['name'],
              'proper_names' => $p_hero['data']['properNames'],
              'level' => $p_hero['level'],
            );
          }

        }

      }

    }
    $export['dotamode'] = $this->dotaModeShort;
    $export['observers'] = $this->observers;
    $export['referees'] = $this->referees;
    $export['chat'] = $this->chat;

    return $export;
  }

  /**
   * Replay Data Parser
   *
   * @param string $filename = 'path/to/file.w3g'
   *
   * @param array $options = array(
   *  'parseActions' => true, //default true
   *  'parseChat' => true, //default true
   *  'logCallback' => 'string|array' // this function will called when calls $this->_log()
   *  'translateCallback' => 'string|array' // this function will called when calls $this->_t()
   * )
   */
  public function __construct($filename, $options = null)
  {

    $this->_filename = (string) $filename;

    if (null !== $options)
    {
      if ($options instanceof Zend_Config)
      {
        $options = $options->toArray();
      }
      elseif (!is_array($options))
      {
        throw new Dota_Replay_Parser_Exception('Invalid options provided; must be a config object, or an array');
      }

      $this->setOptions($options);
    }

    $this->max_datablock = self::MAX_DATABLOCK;

    $this->game['player_count'] = 0;
    $this->extra['parsed_winner'] = '';
  }

  /**
   * Run Replay Parsing
   */
  public function run()
  {
    if (!$this->fp = fopen($this->_filename, 'rb'))
      throw new Dota_Replay_Parser_Exception($this->_filename . ': Can\'t read replay file');

    // Lock the replay for reading
    flock($this->fp, 1);
    $this->_parseHeader();
    $this->_parseData();
    $this->_cleanUp();

    // Unlock the replay
    flock($this->fp, 3);
    fclose($this->fp);

    // Cleanup
    unset($this->fp);
    unset($this->data);
    unset($this->players);
    //unset($this->referees);
    unset($this->time);
    unset($this->pause);
    unset($this->leaves);
    unset($this->max_datablock);
    unset($this->ability_delay);
    unset($this->leave_unknown);
    unset($this->continue_game);

    $this->_parsed = true;

    return $this;
  }

  public function getXmlMapData()
  {
    return Dota_Replay_Parser::getInstance();
  }

  /**
   * Set Parsing Options
   * @param array $options
   */
  public function setOptions(array $options)
  {
    $this->_options = $options;

    $options = array_change_key_case($options, CASE_LOWER);

    if (!empty($options['parseactions']))
    {
      $this->setParseActions($options['parseactions']);
    }

    if (!empty($options['parsechat']))
    {
      $this->setParseChat($options['parsechat']);
    }

    if (!empty($options['translatecallback']))
    {
      $this->setTranslateCallback($options['translatecallback']);
    }

    if (!empty($options['logcallback']))
    {
      $this->setLogCallback($options['logcallback']);
    }

    return $this;
  }

  public function getOptions()
  {
    return $this->_options;
  }

  /**
   * Set Parse Chat Messages
   * @param bool $value true|false
   * @return Dota_Replay
   */
  public function setParseChat($value)
  {
    $this->_parseChat = (bool) $value;

    return $this;
  }

  public function getParseChat()
  {
    return $this->_parseChat;
  }

  /**
   * Set Parse Players Actions
   * @param bool $value true|false
   * @return Dota_Replay
   */
  public function setParseActions($value)
  {
    $this->_parseActions = (bool) $value;

    return $this;
  }

  public function getParseActions()
  {
    return $this->_parseActions;
  }

  /**
   * Set Valid Callback used in translating of replay entries
   * @param callback $callback
   * @return Dota_Replay
   */
  public function setTranslateCallback($callback)
  {
    $this->_translateCallback = $callback;

    return $this;
  }

  /**
   * Set Valid Callback used in debug info logging
   * @param callback $callback
   * @return Dota_Replay
   */
  public function setLogCallback($callback)
  {
    $this->_logCallback = $callback;

    return $this;
  }

  /**
   * Translate string using given translate callback
   * @param string $string
   * @return string
   */
  protected function _t($string)
  {
    if (is_callable($this->_translateCallback))
      return call_user_func($this->_translateCallback, $string);

    return $string;
  }

  /**
   * Debug information using given log callback
   * @param string $string
   */
  protected function _log($string, $priority = 0)
  {
    if (is_callable($this->_logCallback))
      return call_user_func($this->_logCallback, $string, $priority);
  }

  /*
   * Some Data in replay are unknown.
   * This function walk throught this data
   */

  protected function _cutUnknownBytes($count)
  {
    $this->data = substr($this->data, (int) $count);
  }

  /**
   * 2.0 Header parsing
   */
  protected function _parseHeader()
  {
    $data = fread($this->fp, 48);
    if (!$this->header = @unpack('a28intro/Lheader_size/Lc_size/Lheader_v/Lu_size/Lblocks', $data))
    {
      exit('Not a replay file');
    }

    if ($this->header['header_v'] == 0x00) // 2.1 [SubHeader] for header version 0 for WarCraft III with patch <= 1.06
    {
      $data = fread($this->fp, 16);
      $this->header = array_merge($this->header, unpack('Sminor_v/Smajor_v/Sbuild_v/Sflags/Llength/Lchecksum', $data));
      $this->header['ident'] = 'WAR3';
    }
    elseif ($this->header['header_v'] == 0x01) //2.2 [SubHeader] for header version 1 for WarCraft III patch >= 1.07 and TFT replays
    {
      $data = fread($this->fp, 20);
      $this->header = array_merge($this->header, unpack('a4ident/Lmajor_v/Sbuild_v/Sflags/Llength/Lchecksum', $data));
      $this->header['minor_v'] = 0;
      $this->header['ident'] = strrev($this->header['ident']);
    }
  }

  /**
   * Block parsing
   */
  protected function _parseData()
  {
    fseek($this->fp, $this->header['header_size']);
    $blocks_count = $this->header['blocks'];

    for ($i = 0; $i < $blocks_count; $i++)
    {
      // 3.0 [Data block header]
      $block_header = @unpack('Sc_size/Su_size/Lchecksum', fread($this->fp, 8));

      /**
        offset | size/type | Description
        -------+-----------+-----------------------------------------------------------
        c_size     0x0000 |  1  word  | size n of compressed data block (excluding header)
        u_size     0x0002 |  1  word  | size of decompressed data block (currently 8k)
        checksum   0x0004 |  1 dword  | unknown (probably checksum)
        0x0008 |  n bytes  | compressed data (decompress using zlib)
       */
      $temp = fread($this->fp, $block_header['c_size']);

      // First try uncompressing using the header / tail data, for non-WC3 generated replays
      if ($temp_gzun = @gzuncompress($temp))
      {
        $this->data .= $temp_gzun;
      }
      // If that fails assume we're dealing with a WC3 generated replay and use inflate ignoring header / tail info.
      else
      {
        $temp = substr($temp, 2, -4);
        $temp{0} = chr(ord($temp{0}) + 1);

        if ($temp = gzinflate($temp))
        {
          $this->data .= $temp;
        }
        else
        {
          exit($this->filename . ': Incomplete replay file. Block id: ' . $i);
        }
      }

      // 4.0 [Decompressed data]
      /*
        # |   Size   | Name
        ---+----------+--------------------------
        1 |   4 byte | Unknown (0x00000110 - another record id?)
        2 | variable | PlayerRecord (see 4.1)
        3 | variable | GameName (null terminated string) (see 4.2)
        4 |   1 byte | Nullbyte
        5 | variable | Encoded String (null terminated) (see 4.3)
        |          |  - GameSettings (see 4.4)
        |          |  - Map&CreatorName (see 4.5)
        6 |   4 byte | PlayerCount (see 4.6)
        7 |   4 byte | GameType (see 4.7)
        8 |   4 byte | LanguageID (see 4.8)
        9 | variable | PlayerList (see 4.9)
        10 | variable | GameStartRecord (see 4.11)
       */
      // 4.0.1 - The first block contains Game and Player information
      if ($i == 0)
      {
        $this->_cutUnknownBytes(4); //Unknown (0x00000110 - another record id?)

        $this->_parsePlayerRecord(); //PlayerRecord (see 4.1)

        $this->_parseGameName(); //GameName (null terminated string) (see 4.2)

        $this->_cutUnknownBytes(1); //Nullbyte

        $this->_parseEncodedString(); //Encoded String (null terminated) (see 4.3)

        /*
         * After parse Encoded String
         * We know map name and load it's .xml file, that contains all Unit and Items ID's
         */
        $this->getXmlMapData()
          ->setMapName($this->game['map'], $this->game['dota_major'], $this->game['dota_minor'])
          ->parseMap();
        /**
         *
         */
        $this->_parsePlayerCount(); //PlayerCount (see 4.6)

        $this->_parseGameType(); //GameType (see 4.7)

        $this->_parseLanguageId(); //LanguageID (see 4.8)

        $this->_parsePlayerList();

        $this->_parseGameStartRecord();

        $this->_parseSlotRecord();

        $this->_parseRandomSeed();
      }
      else if ($blocks_count - $i < 2)
      {
        $this->max_datablock = 0;
      }

      if ($this->getParseChat() || $this->getParseActions())
      {
        $this->_parseBlocks();
      }
      else
      {
        break;
      }
    }
  }

  /**
   * 4.1 PlayerRecord
   */
  protected function _parsePlayerRecord()
  {
    /*
      offset | size/type | Description
      -------+-----------+-----------------------------------------------------------
      0x0000 |  1 byte   | RecordID:
      |           |  0x00 for game host
      |           |  0x16 for additional players (see 4.9)
      0x0001 |  1 byte   | PlayerID
      0x0002 |  n bytes  | PlayerName (null terminated string)
      n+2 |  1 byte   | size of additional data:
      |           |  0x01 = custom
      |           |  0x08 = ladder

      Depending on the game type one of these records follows:

      o For custom games:

      offset | size/type | Description
      -------+-----------+---------------------------------------------------------
      0x0000 | 1 byte    | null byte (1 byte)

      o For ladder games:

      offset | size/type | Description
      -------+-----------+---------------------------------------------------------
      0x0000 | 4 bytes   | runtime of players Warcraft.exe in milliseconds
      0x0004 | 4 bytes   | player race flags:
      |           |   0x01=human
      |           |   0x02=orc
      |           |   0x04=nightelf
      |           |   0x08=undead
      |           |  (0x10=daemon)
      |           |   0x20=random
      |           |   0x40=race selectable/fixed (see notes in section 4.11)
     */
    $temp = unpack('Crecord_id/Cplayer_id', $this->data); //RecordID 1 byte; PlayerID 2 bytes;
    $this->_cutUnknownBytes(2);

    $player_id = $temp['player_id'];

    $player = array();
    $player['player_id'] = $player_id;
    $player['initiator'] = $this->_convertBool(!$temp['record_id']);

    $player['name'] = '';
    for ($i = 0; $this->data{$i} != "\x00"; $i++)
      $player['name'] .= $this->data{$i};

    // Save names for handling SP
    $this->wc3idToNames[$player_id] = $player['name'];

    // if it's FFA we need to give players some names
    if (!$player['name'])
      $player['name'] = 'Player ' . $player_id;

    $this->_cutUnknownBytes($i + 1);

    // custom game
    if (ord($this->data{0}) == 1)
    {
      $this->_cutUnknownBytes(2);
    }
    // ladder game
    else if (ord($this->data{0}) == 8)
    {
      $this->_cutUnknownBytes(1);
      $temp = unpack('Lruntime/Lrace', $this->data);
      $this->_cutUnknownBytes(8);
      $player['exe_runtime'] = $temp['runtime'];
      $player['race'] = $this->_convertRace($temp['race']);
    }

    if ($this->getParseActions())
      $player['actions'][] = 0;

    // calculating team for tournament replays from battle.net website
    if (!$this->header['build_v'])
      $player['team'] = ($player_id - 1) % 2;

    if (isset($this->game['player_count']))
      $this->game['player_count']++;
    else
      $this->game['player_count'] = 1;

    $this->players[$player_id] = $player;
  }

  /**
   * 4.2 GameName
   */
  protected function _parseGameName()
  {
    // 4.2 [GameName]
    $this->game['name'] = '';
    //read gamename from data until we got chr(0) - null byte
    for ($i = 0; $this->data{$i} != chr(0); $i++)
    {
      $this->game['name'] .= $this->data{$i};
    }
    $this->_cutUnknownBytes($i + 1); // 0-byte ending the string
  }

  /**
   * 4.3 Encoded String (null terminated)
   */
  protected function _parseEncodedString()
  {
    // 4.3 [Encoded String]
    $temp = '';

    for ($i = 0; $this->data{$i} != chr(0); $i++)
    {
      if ($i % 8 == 0)
      {
        $mask = ord($this->data{$i});
      }
      else
      {
        $temp .= chr(ord($this->data{$i}) - !($mask & (1 << $i % 8)));
      }
    }

    // 4.4 [GameSettings]
    $this->game['speed'] = $this->_convertSpeed(ord($temp{0}));

    if (ord($temp{1}) & 1)
    {
      $this->game['visibility'] = $this->_convertVisibility(0);
    }
    else if (ord($temp{1}) & 2)
    {
      $this->game['visibility'] = $this->_convertVisibility(1);
    }
    else if (ord($temp{1}) & 4)
    {
      $this->game['visibility'] = $this->_convertVisibility(2);
    }
    else if (ord($temp{1}) & 8)
    {
      $this->game['visibility'] = $this->_convertVisibility(3);
    }
    $this->game['observers'] = $this->_convertObservers(((ord($temp{1}) & 16) == true) + 2 * ((ord($temp{1}) & 32) == true));
    $this->game['teams_together'] = $this->_convertBool(ord($temp{1}) & 64);

    $this->game['lock_teams'] = $this->_convertBool(ord($temp{2}));

    $this->game['full_shared_unit_control'] = $this->_convertBool(ord($temp{3}) & 1);
    $this->game['random_hero'] = $this->_convertBool(ord($temp{3}) & 2);
    $this->game['random_races'] = $this->_convertBool(ord($temp{3}) & 4);

    if (ord($temp{3}) & 64)
    {
      $this->game['observers'] = $this->_convertObservers(4);
    }

    $temp = substr($temp, 13); // 5 unknown bytes + checksum
    // 4.5 [Map&CreatorName]
    $temp = explode(chr(0), $temp);
    $this->game['creator'] = $temp[1];
    $this->game['map'] = $temp[0];

    $this->_cutUnknownBytes($i + 1); //null + 1 unknown byte
  }

  /**
   * 4.6 Player Count
   */
  protected function _parsePlayerCount()
  {
    $temp = unpack('Lslots', $this->data);
    $this->data = substr($this->data, 4);
    $this->game['slots'] = $temp['slots'];
  }

  /**
   * 4.7 Game Type
   */
  protected function _parseGameType()
  {
    $this->game['type'] = $this->_convertGameType(ord($this->data[0]));
    $this->game['private'] = $this->_convertBool(ord($this->data[1]));

    $this->_cutUnknownBytes(2); //unknown (always 0x0000 so far)
  }

  /*
   * 4.8 LanguageID
   */

  protected function _parseLanguageId()
  {
    $this->data = substr($this->data, 6); // 4.8 [LanguageID] is useless
  }

  /*
   * 4.9 Player List
   */

  protected function _parsePlayerList()
  {
    while (ord($this->data{0}) == 0x16)
    {
      $this->_parsePlayerRecord();
      $this->_cutUnknownBytes(4); //unknown
      //(always 0x00000000 for patch version >= 1.07
      //always 0x00000001 for patch version <= 1.06)
    }
  }

  /*
   * 4.10 Game Start Record
   */

  protected function _parseGameStartRecord()
  {
    $temp = unpack('Crecord_id/Srecord_length/Cslot_records', $this->data);
    $this->data = substr($this->data, 4);
    $this->game = array_merge($this->game, $temp);
  }

  /*
   * 4.11 Slot Record
   */

  protected function _parseSlotRecord()
  {
    $slot_records = $this->game['slot_records'];
    for ($i = 0; $i < $slot_records; $i++)
    {
      if ($this->header['major_v'] >= 7)
      {
        $temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace/Cai_strength/Chandicap', $this->data);
        $this->data = substr($this->data, 9);
      }
      else if ($this->header['major_v'] >= 3)
      {
        $temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace/Cai_strength', $this->data);
        $this->data = substr($this->data, 8);
      }
      else
      {
        $temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace', $this->data);
        $this->data = substr($this->data, 7);
      }

      $temp['color'] = $this->_convertColor($temp['color']);
      $temp['race'] = $this->_convertRace($temp['race']);
      $temp['ai_strength'] = $this->_convertAi($temp['ai_strength']);
      $temp['dota_id'] = $this->_getDotaId($temp['color']);   /* Seven */
      // Used for handling SP mode
      $this->dotaIdToWc3id[$this->_getDotaId($temp['color'])] = $temp['player_id'];

      // do not add empty slots
      if ($temp['slot_status'] == 2 && $temp['player_id'])
      {

        /* Observers */
        if ($temp['team'] == 12)
        {
          $this->observers[$temp['player_id']] = array_merge($this->players[$temp['player_id']], $temp);
        }
        /* Players */
        else
        {
          $this->players[$temp['player_id']] = array_merge($this->players[$temp['player_id']], $temp);
        }
        // Tome of Retraining
        $this->players[$temp['player_id']]['retraining_time'] = 0;
      }
    }
  }

  /*
   * 4.12 Random Seed
   */

  protected function _parseRandomSeed()
  {
    $temp = unpack('Lrandom_seed/Cselect_mode/Cstart_spots', $this->data);
    $this->data = substr($this->data, 6);
    $this->game['random_seed'] = $temp['random_seed'];
    $this->game['select_mode'] = $this->_convertSelectMode($temp['select_mode']);
    if ($temp['start_spots'] != 0xCC)
    { // tournament replays from battle.net website don't have this info
      $this->game['start_spots'] = $temp['start_spots'];
    }
  }

  /**
   * 5.0 ReplayData parsing
   */
  protected function _parseBlocks()
  {
    $data_left = strlen($this->data);
    while ($data_left > $this->max_datablock)
    {

      $prev = (isset($block_id) ? $block_id : 1);
      $block_id = ord($this->data{0});


      switch ($block_id)
      {
        // TimeSlot block
        case 0x1E:
        case 0x1F:
          $temp = unpack('x1/Slength/Stime_inc', $this->data);

          if ($this->pause != 1)
          {
            $this->time += $temp['time_inc'];
          }
          if ($temp['length'] > 2 && $this->getParseActions())
          {
            $this->_parseActions(substr($this->data, 5, $temp['length'] - 2), $temp['length'] - 2);
          }
          $this->data = substr($this->data, $temp['length'] + 3);
          $data_left -= $temp['length'] + 3;
          break;

        // Player chat message (patch version >= 1.07)
        case 0x20:
          // before 1.03 0x20 was used instead 0x22
          if ($this->header['major_v'] > 2)
          {
            $temp = unpack('x1/Cplayer_id/Slength/Cflags/Smode', $this->data);
            if ($temp['flags'] == 0x20)
            {
              $temp['mode'] = $this->_convertChatMode($temp['mode']);
              $temp['text'] = substr($this->data, 9, $temp['length'] - 6);
            }
            elseif ($temp['flags'] == 0x10)
            {
              // those are strange messages, they aren't visible when
              // watching the replay but they are present; they have no mode
              $temp['text'] = substr($this->data, 7, $temp['length'] - 3);
              unset($temp['mode']);
            }
            $this->data = substr($this->data, $temp['length'] + 4);
            $data_left -= $temp['length'] + 4;
            $temp['time'] = $this->_convertTime($this->time);
            $temp['player_name'] = $this->players[$temp['player_id']]['name'];
            $this->chat[] = $temp;
            break;
          }

        // unknown (Random number/seed for next frame)
        case 0x22:
          $temp = ord($this->data{1});
          $this->data = substr($this->data, $temp + 2);
          $data_left -= $temp + 2;
          break;

        // unknown (startblocks)
        case 0x1A:
        case 0x1B:
        case 0x1C:
          $this->data = substr($this->data, 5);
          $data_left -= 5;
          break;
        // unknown (very rare, appears in front of a 'LeaveGame' action)
        case 0x23:
          $this->data = substr($this->data, 11);
          $data_left -= 11;
          break;
        // Forced game end countdown (map is revealed)
        case 0x2F:
          $this->data = substr($this->data, 9);
          $data_left -= 9;
          break;

        // LeaveGame
        case 0x17:
          $this->leaves++;
          $temp = unpack('x1/Lreason/Cplayer_id/Lresult/Lunknown', $this->data);
          $this->players[$temp['player_id']]['time'] = $this->time;
          $this->players[$temp['player_id']]['leave_reason'] = $temp['reason'];
          $this->players[$temp['player_id']]['leave_result'] = $temp['result'];
          $this->data = substr($this->data, 14);
          $data_left -= 14;

          if ($this->leave_unknown)
          {
            $this->leave_unknown = $temp['unknown'] - $this->leave_unknown;
          }

          if ($this->leaves == $this->game['player_count'])
          {
            $this->game['saver_id'] = $temp['player_id'];
            $this->game['saver_name'] = $this->players[$temp['player_id']]['name'];
          }

          if ($temp['reason'] == 0x01)
          {
            switch ($temp['result'])
            {
              case 0x01: $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Disconnect");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break;
              case 0x07: $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Left");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break;
              case 0x08: $this->game['loser_team'] = $this->players[$temp['player_id']]['team'];
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Lost
              case 0x09: $this->game['winner_team'] = $this->players[$temp['player_id']]['team'];
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Won
              case 0x0A: $this->game['loser_team'] = 'tie';
                $this->game['winner_team'] = 'tie';
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Draw
              case 0x0B: $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Left");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break;
            }
          }
          elseif ($temp['reason'] == 0x0C && isset($this->game['saver_id']) && $this->game['saver_id'])
          {
            switch ($temp['result'])
            {
              case 0x01: $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Disconnect");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Disconnect
              case 0x07:
                if ($this->leave_unknown > 0 && $this->continue_game)
                {
                  //$this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
                  $temp['mode'] = "QUIT";
                  $temp['time'] = $this->_convertTime($this->time);
                  $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                  $temp['text'] = $this->_t("Finished");
                  $this->chat[] = $temp;
                  $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                  break; //Saver Won
                }
                else
                {
                  //$this->game['loser_team'] = $this->players[$this->game['saver_id']]['team'];
                  $temp['mode'] = "QUIT";
                  $temp['time'] = $this->_convertTime($this->time);
                  $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                  $temp['text'] = $this->_t("Finished");
                  $this->chat[] = $temp;
                  $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                  break; //Saver Lost
                }
              case 0x08: $this->game['loser_team'] = $this->players[$this->game['saver_id']]['team'];
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Lost

              case 0x09: $this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Won
              case 0x0B: // this isn't correct according to w3g_format but generally works...
                if ($this->leave_unknown > 0)
                {
                  $this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
                  $temp['mode'] = "QUIT";
                  $temp['time'] = $this->_convertTime($this->time);
                  $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                  $temp['text'] = $this->_t("Finished");
                  $this->chat[] = $temp;
                  $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                  break; //Saver Won
                }
            }
          }
          elseif ($temp['reason'] == 0x0C)
          {
            switch ($temp['result'])
            {
              case 0x01: $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Disconnect");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Disconnect
              case 0x07: $this->game['loser_team'] = 99;
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Lost
              case 0x08: $this->game['winner_team'] = $this->players[$temp['player_id']]['team'];
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Lost
              case 0x09: $this->game['winner_team'] = 99;
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Saver Won
              case 0x0A: $this->game['loser_team'] = 'tie';
                $this->game['winner_team'] = 'tie';
                $temp['mode'] = "QUIT";
                $temp['time'] = $this->_convertTime($this->time);
                $temp['player_name'] = $this->players[$temp['player_id']]['name'];
                $temp['text'] = $this->_t("Finished");
                $this->chat[] = $temp;
                $this->players[$temp['player_id']]['leave_result'] = $temp['text'];
                break; //Tie
            }
          }
          $this->leave_unknown = $temp['unknown'];
          break;
        case 0:
          $data_left = 0;
          break;
        default:
          exit('Unhandled replay command block: 0x' . sprintf('%02X', $block_id) . ' (prev: 0x' . sprintf('%02X', $prev) . ', time: ' . $this->time . ') in ' . $this->_filename);
      } // End switch
    }  // End while
  }

  /**
   * Action parsing
   * @param Action Block
   * @param Data length
   */
  protected function _parseActions($actionblock, $data_length)
  {
    $block_length = 0;

    while ($data_length)
    {
      if ($block_length)
      {
        $actionblock = substr($actionblock, $block_length);
      }
      $temp = unpack('Cplayer_id/Slength', $actionblock);
      $player_id = $temp['player_id'];
      $block_length = $temp['length'] + 3;
      $data_length -= $block_length;

      $was_deselect = false;
      $was_subupdate = false;

      $n = 3;

      // The main action block loop
      while ($n < $block_length)
      {

        // Holds a history for the previous action
        $prev = (isset($action) ? $action : 0);
        $action = ord($actionblock{$n});


        switch ($action)
        {
          // Unit/building ability (no additional parameters)
          // here we detect the races, heroes, units, items, buildings,
          // upgrades

          case 0x10:
            $this->players[$player_id]['actions'][] = $this->time;

            if ($this->header['major_v'] >= 13)
            {
              $n++; // ability flag is one byte longer
            }
            $itemid = strrev(substr($actionblock, $n + 2, 4));

            // For debugging only
//              $temp['mode'] = "action";
//              $temp['text'] = $itemid;
//              $temp['time'] = $this->time;
//              $temp['player_name'] = $this->players[$temp['player_id']]['name'];
//              $this->_log(implode(' ', $temp));


            $value = $this->_convertItemId($itemid);
            if (!$value)
            {
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('ability')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')] = 1;
              }

              // Irrelevant to dota - handling Destroyers
              if (ord($actionblock{$n + 2}) == 0x33 && ord($actionblock{$n + 3}) == 0x02)
              {
                $name = substr($this->_convertItemId('ubsp'), 2);
                $this->players[$player_id]['units']['order'][$this->time] = $this->players[$player_id]['units_multiplier'] . ' ' . $name;
                $this->players[$player_id]['units'][$name] += $this->players[$player_id]['units_multiplier'];
                $name = substr($this->_convertItemId('uobs'), 2);
                $this->players[$player_id]['units'][$name] -= $this->players[$player_id]['units_multiplier'];
              }
            }
            else
            {
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('buildtrain')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('buildtrain')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('buildtrain')] = 1;
              }
              if (!isset($this->players[$player_id]['race_detected']) || !$this->players[$player_id]['race_detected'])
              {
                if ($race_detected = $this->_convertRace($itemid))
                {
                  $this->players[$player_id]['race_detected'] = $race_detected;
                }
              }

              // Entity name (unique)
              $name = $value->getName();
              // Entity type (Hero, Skill, Ultimate, Stat, Item)
              $type = $value->getEntityType();

              switch ($type)
              {
                case 'HERO':
                  // Picking in CM mode, we need to get heroes equal to the number of slots... - obs?
                  if ($this->inPickMode)
                  {

                    // Handle duplicated actions
                    if (isset($this->previousPick) && $this->previousPick == $value->getName())
                      continue;

                    $value->extra = $this->players[$player_id]['team'];

                    // 3-2 ban split CM Mode in versions 6.68+
                    if (($this->game['dota_major'] == 6 && $this->game['dota_minor'] >= 68) || $this->game['dota_major'] > 6)
                    {
                      // This action was triggered by CM banning, so ignore it
                      if (!$this->dotaMode->banPhaseComplete())
                        break;

                      // We need to keep checking how many picks have been made,
                      // since we need to start another ban phase for the split CM mode
                      if ($this->dotaMode->getBansPerTeam() == 3)
                      {
                        // We're done with phase 1, and waiting for 6 picks to be made to get to phase 2
                        if ($this->dotaMode->getNumPicked() >= 6)
                        {
                          break;
                        }
                      }

                      // Pick hero
                      $this->dotaMode->addHeroToPicks($value);
                      $this->picks_num++;
                    }
                    // Otherwise we're dealing with the pre 6.68 CM mode of 4 bans
                    else
                    {
                      // The hero was banned, don't add it to the picks.
                      if ($this->bans_num < self::NUM_OF_BANS)
                        break;

                      // Add the picked hero to the array of picked heroes.
                      $this->picks[] = $value;
                      $this->picks_num++;
                    }

                    // Save previous pick to avoid duplication issues
                    $this->previousPick = $value->getName();

                    // End of picking, unpause the game
                    if ($this->picks_num >= self::NUM_OF_PICKS)
                    {
                      $this->pause = false;
                      $this->inPickMode = false;
                    }


                    $this->_log("Added hero to pool in CM mode " . $value->getName() . " ID " . $value->getId() . " for player " . $this->players[$player_id]['name'] . " at " . $this->_convertTime($this->time) . "\r\n");
                  }
                  break;

                case 'SKILL':
                case 'ULTIMATE':
                case 'STAT':

                  // Find the hero with the SkillID in RelatedTo
                  $heroId = $this->getXmlMapData()->skillToHeroMap[$value->getId()];
                  $heroName = $this->getXmlMapData()->hashMap[$heroId]->getName();

                  $pid = $this->players[$player_id]['dota_id'];

                  if (!isset($this->stats[$pid]))
                  {

                    $this->_log("Problematic player id: " . $pid . " \r\n");

                    $this->preAnnounceSkill[$pid] = array('skill' => $value, 'time' => $this->time, 'heroId' => $heroId);
                  }
                  else if (!$this->stats[$pid]->isSetHero())
                  {
                    // Player is skilling, but no hero set yet.
                    // Save the Skill Data and Time, and try to add the skill on cleanup
                    $this->stats[$pid]->addDelayedSkill($value, $this->time, $heroId);
                  }
                  // If skill-to-hero is the same as player's hero or common attribute skill the hero
                  else if (
                    $value->getId() == 'A0NR'
                    || $heroName == "Common"
                    || $heroName == $this->stats[$pid]->getHero()->getName()
                  )
                  {

                    // Check if the current amount of stored skills is not greater then the level
                    // broadcast by Dota
                    // Only check if we're dealing with a new version, where the level was increased at least once
                    if ($this->stats[$pid]->getLevelCap() >= 2)
                    {
                      // TODO - THIS IS DEACTIVATED with "> 0 || 0 =="  AT THE MOMENT
                      // Limiting skilling by the Level broadcasting doesn't seem to be effective due to ID and possible timing issues
                      if ($this->stats[$pid]->getLevelCap() > 0 || 0 == $this->stats[$pid]->getHero()->getLevel())
                      {
                        $this->stats[$pid]->getHero()->setSkill($value, $this->time);

                        $this->_log("Player " . $this->players[$player_id]['name'] . " skilling " . $heroName . " at " . $this->time . "  (PLAYER ID : " . $player_id . ") - DOTA ID: " . $this->players[$player_id]['dota_id'] . " Translated: " . $this->dotaIdToWc3id[$this->players[$player_id]['dota_id']] . "\r\n");
                      }
                      else
                      {
                        $this->_log("Player " . $this->players[$player_id]['name'] . " failed skilling " . $heroName . " due to LEVEL CAP at " . $this->time . "\r\n");
                      }
                    }
                    else
                    {
                      $this->stats[$pid]->getHero()->setSkill($value, $this->time);

                      $this->_log("Player " . $this->players[$player_id]['name'] . " skilling " . $heroName . " at " . $this->time . " (PLAYER ID : " . $player_id . ")\r\n");
                    }
                  }
                  // Otherwise assume the player's skilling a Hero not owned by him
                  else
                  {
                    if (isset($this->activatedHeroes[$heroName]))
                    {
                      $this->activatedHeroes[$heroName]->setSkill($value, $this->time);
                    }

                    $this->_log("Player " . $this->players[$player_id]['name'] . " skilling non-owned " . $heroName . " at " . $this->time . " (PLAYER ID : " . $player_id . ") - DOTA ID: " . $this->players[$player_id]['dota_id'] . " Translated: " . $this->translatedDotaID[$this->players[$player_id]['dota_id']] . " \r\n");
                  }

                  break;

                case 'ITEM':
                  $this->players[$player_id]['items'][$this->_convertTime($this->time)] = $value;
                  break;

                // Irrelevant to dota for now.
                case 'p':
                  // preventing duplicated upgrades
                  if ($this->time - $this->players[$player_id]['upgrades_time'] > self::ACTION_DELAY || $itemid != $this->players[$player_id]['last_itemid'])
                  {
                    $this->players[$player_id]['upgrades_time'] = $this->time;
                    $this->players[$player_id]['upgrades']['order'][$this->time] = $name;
                    if (isset($this->players[$player_id]['upgrades'][$name]))
                    {
                      $this->players[$player_id]['upgrades'][$name]++;
                    }
                    else
                    {
                      $this->players[$player_id]['upgrades'][$name] = 1;
                    }
                  }
                  break;
                // Irrelevant to dota for now.
                case 'UNIT':
                  // preventing duplicated units
                  if (($this->time - $this->players[$player_id]['units_time'] > self::ACTION_DELAY || $itemid != $this->players[$player_id]['last_itemid'])
                    // at the beginning of the game workers are queued very fast, so
                    // it's better to omit action delay protection
                    || (($itemid == 'hpea' || $itemid == 'ewsp' || $itemid == 'opeo' || $itemid == 'uaco') && $this->time - $this->players[$player_id]['units_time'] > 0))
                  {
                    $this->players[$player_id]['units_time'] = $this->time;
                    $this->players[$player_id]['units']['order'][$this->time] = $this->players[$player_id]['units_multiplier'] . ' ' . $name;
                    $this->players[$player_id]['units'][$name] += $this->players[$player_id]['units_multiplier'];
                  }
                  break;
                // Irrelevant to dota for now.
                case 'BUILDING':
                  $this->players[$player_id]['buildings']['order'][$this->time] = $name;
                  if (isset($this->players[$player_id]['buildings'][$name]))
                  {
                    $this->players[$player_id]['buildings'][$name]++;
                  }
                  else
                  {
                    $this->players[$player_id]['buildings'][$name] = 1;
                  }
                  break;
                case 'ERROR':
                  $this->errors[$this->time] = $this->players[$player_id]['name'] . ': Unknown SkillID: ' . $value;
                  break;
                default:
                  $this->errors[$this->time] = $this->players[$player_id]['name'] . ': Unknown ItemID: ' . $value;
                  break;
              }
              $this->players[$player_id]['last_itemid'] = $itemid;
            }

            $n+=14;
            break;

          // Unit/building ability (with target position)
          case 0x11:
            //// Was in the middle of working on the coordinants here... This is where I stopped.
// $temp1 = unpack('CPlayer/SLength/Caction/SAbilityFlags/LItemID/Lfooa/Lfoob/fx/fy/Lfooc/Lfood', $actionblock);
// if (!isset ){}$temp1['x']/8192*64;
// $y = $temp1['y']/8192*64;
// echo $x.'<br />'.$y.'<br /><br />';
            $this->players[$player_id]['actions'][] = $this->time;
            if ($this->header['major_v'] >= 13)
            {
              $n++; // ability flag
            }
            if (ord($actionblock{$n + 2}) <= 0x19 && ord($actionblock{$n + 3}) == 0x00)
            { // basic commands
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('basic')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')] = 1;
              }
            }
            else
            {
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('ability')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')] = 1;
              }
            }
            $value = strrev(substr($actionblock, $n + 2, 4));
            if ($value = $this->_convertBuildingId($value))
            {
              $this->players[$player_id]['buildings']['order'][$this->time] = $value;
              if (isset($this->players[$player_id]['buildings'][$value]))
              {
                $this->players[$player_id]['buildings'][$value]++;
              }
              else
              {
                $this->players[$player_id]['buildings'][$value] = 1;
              }
            }
            $n+=22;
            break;

          // Unit/building ability (with target position and target object ID)
          case 0x12:

            $this->players[$player_id]['actions'][] = $this->time;
            if ($this->header['major_v'] >= 13)
            {
              $n++; // ability flag
            }
            if (ord($actionblock{$n + 2}) == 0x03 && ord($actionblock{$n + 3}) == 0x00)
            { // rightclick
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')] = 1;
              }
            }
            else if (ord($actionblock{$n + 2}) <= 0x19 && ord($actionblock{$n + 3}) == 0x00)
            { // basic commands
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('basic')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')] = 1;
              }
            }
            else
            {
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('ability')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')] = 1;
              }
            }

            $n+=30;
            break;

          // Give item to Unit / Drop item on ground
          case 0x13:
            $this->players[$player_id]['actions'][] = $this->time;
            if ($this->header['major_v'] >= 13)
            {
              $n++; // ability flag
            }
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('item')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('item')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('item')] = 1;
            }
            $n+=38;
            break;

          // Unit/building ability (with two target positions and two item IDs)
          case 0x14:
            $this->players[$player_id]['actions'][] = $this->time;
            if ($this->header['major_v'] >= 13)
            {
              $n++; // ability flag
            }
            if (ord($actionblock{$n + 2}) == 0x03 && ord($actionblock{$n + 3}) == 0x00)
            { // rightclick
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('rightclick')] = 1;
              }
            }
            elseif (ord($actionblock{$n + 2}) <= 0x19 && ord($actionblock{$n + 3}) == 0x00)
            { // basic commands
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('basic')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('basic')] = 1;
              }
            }
            else
            {
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('ability')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('ability')] = 1;
              }
            }
            $n+=43;
            break;

          // Change Selection (Unit, Building, Area)
          case 0x16:
            $temp = unpack('Cmode/Snum', substr($actionblock, $n + 1, 3));
            if ($temp['mode'] == 0x02 || !$was_deselect)
            {
              $this->players[$player_id]['actions'][] = $this->time;
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('select')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('select')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('select')] = 1;
              }
            }
            $was_deselect = ($temp['mode'] == 0x02);

            $this->players[$player_id]['units_multiplier'] = $temp['num'];
            $n+=4 + ($temp['num'] * 8);
            break;

          // Assign Group Hotkey
          case 0x17:
            $this->players[$player_id]['actions'][] = $this->time;
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('assignhotkey')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('assignhotkey')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('assignhotkey')] = 1;
            }
            $temp = unpack('Cgroup/Snum', substr($actionblock, $n + 1, 3));
            if (isset($this->players[$player_id]['hotkeys'][$temp['group']]['assigned']))
            {
              $this->players[$player_id]['hotkeys'][$temp['group']]['assigned']++;
            }
            else
            {
              $this->players[$player_id]['hotkeys'][$temp['group']]['assigned'] = 1;
            }
            $this->players[$player_id]['hotkeys'][$temp['group']]['last_totalitems'] = $temp['num'];

            $n+=4 + ($temp['num'] * 8);
            break;

          // Select Group Hotkey
          case 0x18:
            $this->players[$player_id]['actions'][] = $this->time;
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('selecthotkey')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('selecthotkey')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('selecthotkey')] = 1;
            }
            if (isset($this->players[$player_id]['hotkeys'][ord($actionblock{$n + 1})]['used']))
            {
              $this->players[$player_id]['hotkeys'][ord($actionblock{$n + 1})]['used']++;
            }
            else
            {
              $this->players[$player_id]['hotkeys'][ord($actionblock{$n + 1})]['used'] = 1;
            }

            $this->players[$player_id]['units_multiplier'] = $this->players[$player_id]['hotkeys'][ord($actionblock{$n + 1})]['last_totalitems'];
            $n+=3;
            break;

          // Select Subgroup
          case 0x19:
            // OR is for torunament reps which don't have build_v
            if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14)
            {
              if ($was_subgroup)
              { // can't think of anything better (check action 0x1A)
                $this->players[$player_id]['actions'][] = $this->time;
                if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')]))
                {
                  $this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')]++;
                }
                else
                {
                  $this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')] = 1;
                }

                // I don't have any better idea what to do when somebody binds buildings
                // of more than one type to a single key and uses them to train units
                $this->players[$player_id]['units_multiplier'] = 1;
              }
              $n+=13;
            }
            else
            {
              if (ord($actionblock{$n + 1}) != 0 && ord($actionblock{$n + 1}) != 0xFF && !$was_subupdate)
              {
                $this->players[$player_id]['actions'][] = $this->time;
                if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')]))
                {
                  $this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')]++;
                }
                else
                {
                  $this->players[$player_id]['actions_details'][$this->_convertAction('subgroup')] = 1;
                }
              }
              $was_subupdate = (ord($actionblock{$n + 1}) == 0xFF);
              $n+=2;
            }
            break;

          // some subaction holder?
          // version < 14b: Only in scenarios, maybe a trigger-related command
          case 0x1A:
            // OR is for torunament reps which don't have build_v
            if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14)
            {
              $n+=1;
              $was_subgroup = ($prev == 0x19 || $prev == 0); //0 is for new blocks which start from 0x19
            }
            else
            {
              $n+=10;
            }
            break;

          // Only in scenarios, maybe a trigger-related command
          // version < 14b: Select Ground Item
          case 0x1B:
            // OR is for torunament reps which don't have build_v
            if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14)
            {
              $n+=10;
            }
            else
            {
              $this->players[$player_id]['actions'][] = $this->time;
              $n+=10;
            }
            break;

          // Select Ground Item
          // version < 14b: Cancel hero revival (new in 1.13)
          case 0x1C:
            // OR is for torunament reps which don't have build_v
            if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14)
            {
              $this->players[$player_id]['actions'][] = $this->time;
              $n+=10;
            }
            else
            {
              $this->players[$player_id]['actions'][] = $this->time;
              $n+=9;
            }
            break;

          // Cancel hero revival
          // Remove unit from building queue
          case 0x1D:
          case 0x1E:
            // OR is for torunament reps which don't have build_v
            if (($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) && $action != 0x1E)
            {
              $this->players[$player_id]['actions'][] = $this->time;
              $n+=9;
            }
            else
            {
              $this->players[$player_id]['actions'][] = $this->time;
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('removeunit')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('removeunit')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('removeunit')] = 1;
              }
              $value = $this->_convertItemId(strrev(substr($actionblock, $n + 2, 4)));
              $name = substr($value, 2);
              switch ($value{0})
              {
                case 'u':
                  // preventing duplicated units cancellations
                  if ($this->time - $this->players[$player_id]['runits_time'] > self::ACTION_DELAY || $value != $this->players[$player_id]['runits_value'])
                  {
                    $this->players[$player_id]['runits_time'] = $this->time;
                    $this->players[$player_id]['runits_value'] = $value;
                    $this->players[$player_id]['units']['order'][$this->time] = '-1 ' . $name;
                    $this->players[$player_id]['units'][$name]--;
                  }
                  break;
                case 'b':
                  $this->players[$player_id]['buildings'][$name]--;
                  break;
                case 'h':
                  $this->players[$player_id]['heroes'][$name]['revivals']--;
                  break;
                case 'p':
                  // preventing duplicated upgrades cancellations
                  if ($this->time - $this->players[$player_id]['rupgrades_time'] > self::ACTION_DELAY || $value != $this->players[$player_id]['rupgrades_value'])
                  {
                    $this->players[$player_id]['rupgrades_time'] = $this->time;
                    $this->players[$player_id]['rupgrades_value'] = $value;
                    $this->players[$player_id]['upgrades'][$name]--;
                  }
                  break;
              }
              $n+=6;
            }
            break;

          // Found in replays with patch version 1.04 and 1.05.
          case 0x21:
            $n+=9;
            break;

          // Change ally options
          case 0x50:
            $n+=6;
            break;

          // Transfer resources
          case 0x51:
            $n+=10;
            break;

          // Map trigger chat command
          // Mode can be detected here, as can the usage of -di, -fs, -ma etc.
          // TODO - Use this information
          case 0x60:
            $n+=9; // Two DWORDS + ID
            $str = "";
            while ($actionblock{$n} != "\x00")
            {
              $str .= $actionblock{$n};
              $n++;
            }
            $n+=1;

            $this->_log("Trigger chat command: " . $str . " at " . $this->_convertTime($this->time) . "\r\n");

            break;

          // ESC pressed
          case 0x61:
            $this->players[$player_id]['actions'][] = $this->time;
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('esc')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('esc')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('esc')] = 1;
            }
            $n+=1;
            break;

          // Scenario Trigger
          case 0x62:
            if ($this->header['major_v'] >= 7)
            {
              $n+=13;
            }
            else
            {
              $n+=9;
            }
            break;

          // Enter select hero skill submenu for WarCraft III patch version <= 1.06
          case 0x65:
            $this->players[$player_id]['actions'][] = $this->time;
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')] = 1;
            }
            $n+=1;
            break;

          // Enter select hero skill submenu
          // Enter select building submenu for WarCraft III patch version <= 1.06
          case 0x66:
            $this->players[$player_id]['actions'][] = $this->time;
            if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')]))
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')]++;
            }
            else
            {
              $this->players[$player_id]['actions_details'][$this->_convertAction('heromenu')] = 1;
            }
            $n+=1;
            break;

          // Enter select building submenu
          // Minimap signal (ping) for WarCraft III patch version <= 1.06
          case 0x67:
            if ($this->header['major_v'] >= 7)
            {
              $this->players[$player_id]['actions'][] = $this->time;
              if (isset($this->players[$player_id]['actions_details'][$this->_convertAction('buildmenu')]))
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('buildmenu')]++;
              }
              else
              {
                $this->players[$player_id]['actions_details'][$this->_convertAction('buildmenu')] = 1;
              }
              $n+=1;
            }
            else
            {
              $n+=13;
            }
            break;

          // Minimap signal (ping)
          // Continue Game (BlockB) for WarCraft III patch version <= 1.06
          case 0x68:
            $n+=13;
            break;

          // Continue Game (BlockB)
          // Continue Game (BlockA) for WarCraft III patch version <= 1.06
          case 0x69:
          // Continue Game (BlockA)
          case 0x6A:
            $this->continue_game = 1;
            $n+=17;
            break;

          // Pause game
          case 0x01:
            $this->pause = 1;
            $n+=1;
            break;

          // Resume game
          case 0x02:
            $this->pause = 0;
            $n+=1;
            break;

          // Increase game speed in single player game (Num+)
          case 0x04:
            $n+=1;
            break;
          // Decrease game speed in single player game (Num-)
          case 0x05:
            $n+=1;
            break;

          // Set game speed in single player game (options menu)
          case 0x03:
            $n+=2;
            break;

          // Save game
          case 0x06:
            $i = 1;
            while ($actionblock{$n} != "\x00")
            {
              $n++;
            }
            $n+=1;
            $temp['time'] = $this->_convertTime($this->time);
            $temp['mode'] = '<span class="red">Saving game ?</span>';
            $temp['text'] = 'Save game.';
            $temp['player_name'] = $this->players[$temp['player_id']]['name'];
            $this->chat[] = $temp;
            break;

          // Save game finished
          case 0x07:
            $n+=5;
            break;

          // Only in scenarios, maybe a trigger-related command
          case 0x75:
            $n+=2;
            break;

          /*
            - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            0x6B - SyncStoredInteger actions           [ n bytes ] [APM-]
            - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            n byte  - GameCache  - null terminated string - On the observeded replays it seems to be always the same: A trigger name or identifier? -
            n byte  - DataString - null terminated string - Player slot as a string. This info can and will be overridden by action 0x70 in replay of dota 6.39b and 6.39.
            n byte  - KeyString        - null terminated string - stat identifier, so far:
            "1" : kills,
            "2" : deaths,
            "3" : creep kills,
            "4" : creep denies,
            "5" : Assists,
            "6" : EndGold,
            "7" : Neutrals,
            "8_x" : Inventory
            "9" : Hero
            1 dword - Value      - stat value associated to each identifier category.
           */

          case 0x6B:
            $GameCache = "";
            $DataString = "";
            $DataKey = "";
            $value = "";

            /* Get GameCache */
            while ($n < $block_length && $actionblock{$n} != "\x00")
            {
              $n++;
              $GameCache .= $actionblock{$n};
            }

            $n+=1;

            /* Get DataString */
            while ($n < $block_length && $actionblock{$n} != "\x00")
            {
              $DataString .= $actionblock{$n};
              $n++;
            }
            $n+=1;

            /* Get Key */
            while ($n < $block_length && $actionblock{$n} != "\x00")
            {
              $DataKey .= $actionblock{$n};
              $n++;
            }
            $n+=1;

            // In the case of the Key being 8, we're dealing with items, so we get the Item information as an object
            // In the case of the Key being 9, we're dealing with heroes, so we get the Hero information as an object
            if ($DataKey{0} == "8" || $DataKey == "9")
            {
              $value = strrev(substr($actionblock, $n, 4));

              if ($value == "\0\0\0\0")
              {
                $value = 0;
              }
              else
              {
                $value = $this->_convertItemId($value);
              }
            }
            // Otherwise $value holds the raw string.
            else
            {
              $value = unpack("Lval", substr($actionblock, $n, 4));
            }


            // Handle mode / bans / pools / picks
            if ($DataString == "Data")
            {

              /*
                // Setting levels
                if("Level" == substr($DataKey, 0, 5)) {
                $level = substr($DataKey, 5);
                $c_pid = $value['val'];


                if(isset($this->stats[$l_pid])) {
                $this->stats[$l_pid]->setLevelCap($level);

                if(DEBUG_ON) {
                echo "Set level cap of [".$level."] for player ID [".$renamedWC3PlayerID."] named ".$this->players[$renamedWC3PlayerID]['name']." l_pid [".$l_pid."] <br />";
                }
                }
                }
               */

              // Detect POTM Arrow Accuracy (post 6.68)
              if (substr($DataKey, 0, strlen("AA_Total")) == "AA_Total")
              {
                $pid = substr($DataKey, strlen("AA_Total"));
                if (isset($this->stats[$pid]))
                {
                  $this->stats[$pid]->AA_Total = $value['val'];
                }
              }
              // Detect POTM Arrow Accuracy (post 6.68)
              else if (substr($DataKey, 0, strlen("AA_Hits")) == "AA_Hits")
              {
                $pid = substr($DataKey, strlen("AA_Hits"));
                if (isset($this->stats[$pid]))
                {
                  $this->stats[$pid]->AA_Hits = $value['val'];
                }
              }
              // Detect Pudge Hook Accuracy (post 6.68)
              else if (substr($DataKey, 0, strlen("HA_Total")) == "HA_Total")
              {
                $pid = substr($DataKey, strlen("HA_Total"));
                if (isset($this->stats[$pid]))
                {
                  $this->stats[$pid]->HA_Total = $value['val'];
                }
              }
              // Detect Pudge Hook Accuracy (post 6.68)
              else if (substr($DataKey, 0, strlen("HA_Hits")) == "HA_Hits")
              {
                $pid = substr($DataKey, strlen("HA_Hits"));
                if (isset($this->stats[$pid]))
                {
                  $this->stats[$pid]->HA_Hits = $value['val'];
                }
              }

              // Detect mode
              if (strstr($DataKey, "Mode") !== false)
              {
                $shortMode = substr($DataKey, 4, 2);

                $this->dotaModeShort = $shortMode;
                switch ($shortMode)
                {
                  case "cd":
                    $this->dotaMode = new Dota_Replay_Parser_Modes_Cd();
                    break;
                  case "cm":
                    $this->dotaMode = new Dota_Replay_Parser_Modes_Cm();
                    break;
                }
              }


              // CD Mode - TODO
              // At the moment the CD Mode broadcasting seems to be broken in DOTA 6.66?
              // Can only find Sentinel packets in 0x6B packets
              if (isset($this->dotaMode) && $this->dotaMode->getShortName() == "cd")
              {
                /*
                  $entity_id = strrev(substr($actionblock, $n, 4));
                  $entity = convert_itemid($entity_id);

                  // CD - Constructing hero pool
                  if ( strstr($DataKey, "Pool") !== false) {
                  $this->dotaMode->addHeroToPool($entity);
                  }

                  // Gather bans
                  else if ( strstr($DataKey, "Ban") !== false ) {
                  if( !$this->inPickMode ) {
                  $this->inPickMode = true;
                  $this->pause = true;
                  }


                  if($DataKey == "Ban1") {
                  $entity->extra = 0;
                  }
                  else if ($DataKey == "Ban7") {
                  $entity->extra = 1;
                  }

                  $this->dotaMode->addHeroToBans($entity);
                  }

                  // Gather picks
                  else if ( strstr($DataKey, "Pick") !== false ) {
                  if($DataKey == "Pick1") {
                  $entity->extra = 0;
                  }
                  else if ($DataKey == "Pick7") {
                  $entity->extra = 1;
                  }

                  $this->dotaMode->addHeroToBans($entity);
                  }

                  // Unpause the game - TODO Nonstatic number
                  if ( $this->dotaMode->getNumPicked() >= 10 ) {
                  $this->inPickMode = false;
                  $this->pause = false;

                  $this->bans = $this->dotaMode->getBans();
                  $this->picks = $this->dotaMode->getPicks();
                  }
                 */
              }
              else if (strstr($DataKey, "Ban") !== false)
              {
                // Detected CM mode
                if (!$this->inPickMode)
                {
                  $this->inPickMode = true;
                  $this->pause = true;
                }

                $entity_id = strrev(substr($actionblock, $n, 4));
                $entity = $this->_convertItemId($entity_id);

                if (isset($this->slotToPlayerMap[$DataKey{3}]))
                {
                  $team_pid = $this->slotToPlayerMap[$DataKey{3}];
                }
                else
                {
                  $team_pid = $DataKey{3};
                }
                if ($DataKey == "Ban1")
                {
                  $entity->extra = 0;
                }
                else if ($DataKey == "Ban7")
                {
                  $entity->extra = 1;
                }
                else
                {
                  $entity->extra = $this->players[$team_pid]['team'];
                }

                // 3-2 ban split CM Mode in versions 6.68+
                if (($this->game['dota_major'] == 6 && $this->game['dota_minor'] >= 68) || $this->game['dota_major'] > 6)
                {
                  // If we've already got all bans for phase 1 (6 bans) and we get a new ban action,
                  // then we need to start phase 2
                  if ($this->dotaMode->banPhaseComplete())
                  {
                    $this->dotaMode->setBansPerTeam(5);
                  }

                  $this->dotaMode->addHeroToBans($entity);
                }
                else
                {
                  $this->bans[] = $entity;
                  $this->bans_num++;
                }
              }
            }
            // Determine the winner if possible
            else if ("Global" == $DataString)
            {

              if ("Winner" == $DataKey)
              {

                // 1 = Sentinel and 2 = Scourge
                $winner = $value['val'];

                $this->extra['parsed_winner'] = ($value['val'] == 1 ? "Sentinel" : "Scourge");
              }
            }

            // Handle hero assignment and stats collecting
            if (is_numeric($DataString) && $DataString > -1 && $DataString < 13)
            {

              // Map the Slot ID to the proper player ID (Wc3 - dota)
              if (isset($this->slotToPlayerMap[$DataString]))
              {
                $pid = $this->slotToPlayerMap[$DataString];
              }
              else
              {
                $pid = $DataString;
              }

              // Set heroes for players, including swap & repick handling
              if ($DataKey == 9)
              {

                // This is a failsafe for when a random hero is picked by the game in CM / CD? mode
                if ($this->inPickMode)
                {
                  $this->pause = false;
                  $this->inPickMode = false;
                }


                $x_pid = $pid;
                $x_hero = $value;

                // End game stats, no hero picked by player
                if (!is_object($x_hero))
                {
                  // Handle? - TODO
                }
                // If hero picked before player IDs are sent out
                else if (!isset($this->stats[$x_pid]))
                {
                  $this->preAnnouncePick[$x_pid] = $x_hero;
                }

                // Set hero for player if player's hero ain't set yet
                else if (!$this->stats[$x_pid]->isSetHero())
                {
                  // Assign as player's hero
                  $this->stats[$x_pid]->setHero(new ActivatedHero($x_hero));

                  // Add to Activated Heroes list
                  $this->activatedHeroes[$x_hero->getName()] = $this->stats[$x_pid]->getHero();
                }

                // Player's either swapping, repicking or hero was hero-replaced at the end
                else
                {

                  // If the Hero's already been Activated either swapping or end game's taking place
                  if (isset($this->activatedHeroes[$x_hero->getName()]))
                  {

                    // Swapping taking place
                    if ($this->stats[$x_pid]->getHero()->getName() != $x_hero->getName())
                    {
                      // Update ownership of previously Activated Hero
                      $this->stats[$x_pid]->setHero($this->activatedHeroes[$x_hero->getName()]);
                    }
                    // End game statistics
                    else
                    {
                      // Todo
                    }
                  }

                  // Hero-replacement ( Ezalor, etc) or repicking's taking place
                  else
                  {

                    // If the name matches we're dealing with a morphing ability, otherwise it's repicking
                    if ($this->stats[$x_pid]->getHero()->getName() != $x_hero->getName())
                    {

                      // Assign as player's new hero
                      $this->stats[$x_pid]->setHero(new ActivatedHero($x_hero));

                      // Add to Activated Heroes list
                      $this->activatedHeroes[$x_hero->getName()] = $this->stats[$x_pid]->getHero();
                    }
                  }
                }
              }



              // Stats collecting
              switch ($DataKey{0})
              {
                case "i":      // ID
                  $pid = $value['val'];

                  /*
                    // We're dealing with a switch
                    if(isset($this->SlotToPlayerMap[$DataString]) && $this->SlotToPlayerMap[$DataString] != $pid) {
                    // Update the Dota_ID
                    $this->players[$DataString]['dota_id'] = $pid;
                    echo "CHANGING PID";
                    }
                   */

                  $this->slotToPlayerMap[$DataString] = $pid;

                  // For handling SP
                  $this->translatedDotaID[$pid] = $DataString;

                  if (!isset($this->stats[$pid]))
                  {
                    $this->stats[$pid] = new PlayerStats($pid);

                    // Check if there's any pending delayed hero for the player
                    if (isset($this->preAnnouncePick[$DataString]))
                    {
                      $x_hero = $this->preAnnouncePick[$DataString];
                      $this->stats[$pid]->setHero(new ActivatedHero($x_hero));

                      // Add to Activated Heroes list
                      $this->activatedHeroes[$x_hero->getName()] = $this->stats[$pid]->getHero();
                    }
                    // Check if there's any pending delayed skilling for the player
                    if (isset($this->preAnnounceSkill[$pid]))
                    {
                      $this->stats[$pid]->addDelayedSkill(
                        $this->preAnnounceSkill[$pid]['skill'],
                        $this->preAnnounceSkill[$pid]['time'],
                        $this->preAnnounceSkill[$pid]['heroId']);
                    }
                  }
                  break;
                case "1":
                  $this->stats[$pid]->heroKills = $value['val'];
                  break;
                case "2":
                  $this->stats[$pid]->deaths = $value['val'];
                  break;
                case "3":
                  $this->stats[$pid]->creepKills = $value['val'];
                  break;
                case "4":
                  $this->stats[$pid]->creepDenies = $value['val'];
                  break;
                case "5":
                  $this->stats[$pid]->assists = $value['val'];
                  break;
                case "6":
                  $this->stats[$pid]->endGold = $value['val'];
                  break;
                case "7":
                  $this->stats[$pid]->neutrals = $value['val'];
                  break;
                // Inventory
                case "8":
                  if (isset($this->stats[$pid]))
                  {
                    $this->stats[$pid]->inventory[$DataKey{2}] = $value;
                  }
                  break;
              }
            }

            $this->_log("\r\n Debug time: " . $this->_convertTime($this->time) . " \r\n");
            $this->_log("GameCache: " . $GameCache . " \r\n");
            $this->_log("MissionKey: " . $DataString . " \r\n");
            $this->_log("Key: " . $DataKey . " \r\n");
            $this->_log("Value: " . (is_object($value) ? $value->getName() : $value['val']) . " \r\n\r\n");

            $n+=4; // 1 dword aka value
            break;

          /* Add Seven - Most likely outdated after 59c */
          /* Didn't exactly work out as described. using assumed 28 byte size for now...
            - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            0x70 - Unknown                               [ n bytes ] [APM-]
            - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            n byte  - unknown1 null terminated string - seems to be "dr.x" each time so far. See action 0x6B anyway.
            n byte  - unknown2 null terminated string
            n byte  - unknown3 null terminated string

            Notes:
            o Observed in dota replay version 6.39 and 6.39b - at end of the game. Not used anymore starting in 6.44.
            o See action 0x6B the unknown* of 0x6B match the unknown* of this action.
            o This action was used to determine the winner side based on unknown3 value 1=sentinel , 2=scourge.
           */
          case 0x70:
            /* while ($actionblock{$n} != "\x00") {
              $n++;
              }
              $n+=1; //First string

              while ($actionblock{$n} != "\x00") {
              $n++;
              }
              $n+=1; //Second string

              while ($actionblock{$n} != "\x00") {
              $n++;
              }
              $n+=1; //Third string
             */
            $n+=28;
            break;


          default:
            $temp = '';

            for ($i = 3; $i < $n; $i++)
            {
              $temp .= sprintf('%02X', ord($actionblock{$i})) . ' ';
            }

            $temp .= '[' . sprintf('%02X', ord($actionblock{$n})) . '] ';
            $alen = strlen($actionblock);
            for ($i = 1; $n + $i < $alen; $i++)
            {
              $temp .= sprintf('%02X', ord($actionblock{$n + $i})) . ' ';
            }

            $this->errors[$this->time] = $this->players[$player_id]['name'] . ': Unknown action: 0x' . sprintf('%02X', $action) . ', prev: 0x' . sprintf('%02X', $prev) . ', Player_id=' . $this->players[$player_id]['name'] . ' dump: ' . $temp;
            $n+=2;
          //echo $this->errors[$this->time];
        }
      }
      $was_deselect = ($action == 0x16);
      $was_subupdate = ($action == 0x19);
    }
  }

  protected function _cleanUp()
  {

    if (isset($this->dotaMode))
    {
      // Legacy support for picks / bans
      $this->bans = (count($this->bans) > 0 ? $this->bans : $this->dotaMode->getBans());
      $this->picks = (count($this->picks) > 0 ? $this->picks : $this->dotaMode->getPicks());
    }

    $this->bans_num = count($this->bans);
    $this->picks_num = count($this->picks);

    // Process delayed skills
    foreach ($this->stats as $player)
    {
      $player->processDelayedSkills();
    }

    $wc3idToTime = array();
    $wc3idToLeaveResult = array();
    $wc3idToItems = array();
    $wc3idToActions = array();

    // Construct leave results for handling switch
    foreach ($this->players as $player)
    {
      if (!isset($player['player_id']) || !isset($player['dota_id']))
        continue;

      // Handle player left events
      if (isset($player['time']))
      {
        $wc3idToTime[$player['player_id']] = $player['time'];
      }
      else
      {
        $wc3idToTime[$player['player_id']] = $player['time'] = $this->header['length'];
      }
      if (isset($player['leave_result']))
      {
        $wc3idToLeaveResult[$player['player_id']] = $player['leave_result'];
      }
      else
      {
        $wc3idToLeaveResult[$player['player_id']] = "Finished";
      }

      $wc3idToItems[$player['player_id']] = isset($player['items']) ? $player['items'] : "";
      $wc3idToActionsDetails[$player['player_id']] = isset($player['actions_details']) ? $player['actions_details'] : "";
      $wc3idToActions[$player['player_id']] = isset($player['actions']) ? $player['actions'] : "";
    }

    // Player's time cleanup
    foreach ($this->players as $player)
    {
      if (!isset($player['player_id']))
        continue;

      // Get player's WC3 ID
      $wc3pid = $player['player_id'];

      // Get 'game' dota ID of player
      if (!isset($player['dota_id']))
        continue;

      $gameDotaId = $player['dota_id'];

      // Get 'internal' dota ID of player
      $intDotaId = $this->translatedDotaID[$gameDotaId];

      // Get base game ID based on internal ID
      $baseGameDotaId = $this->_getGameDotaId($intDotaId);

      // Get the renamed WC3 player ID after shuffling
      $renamedWC3PlayerID = $this->dotaIdToWc3id[$baseGameDotaId];

      // Change the name
      if (isset($this->wc3idToNames[$renamedWC3PlayerID]))
      {
        $this->players[$wc3pid]['name'] = $this->wc3idToNames[$renamedWC3PlayerID];
      }
      else
      {
        $this->players[$wc3pid]['time'] = "0";
        $this->players[$wc3pid]['leave_result'] = "";
        $this->players[$wc3pid]['items'] = "";
        $this->players[$wc3pid]['actions_details'] = "";
        $this->players[$wc3pid]['actions'] = "error";
        continue;
      }

      // For handling SWITCH and leave time / result / items and actions
      $this->players[$wc3pid]['time'] = $wc3idToTime[$renamedWC3PlayerID];
      $this->players[$wc3pid]['leave_result'] = $wc3idToLeaveResult[$renamedWC3PlayerID];
      $this->players[$wc3pid]['items'] = $wc3idToItems[$renamedWC3PlayerID];
      $this->players[$wc3pid]['actions_details'] = $wc3idToActionsDetails[$renamedWC3PlayerID];
      $this->players[$wc3pid]['actions'] = $wc3idToActions[$renamedWC3PlayerID];

      if (!isset($player['time']) || !$player['time'])
      {
        $this->players[$player['player_id']]['time'] = $this->header['length'];
      }
    }


    // APM
    foreach ($this->players as $player)
    {
      if (!isset($player['player_id']) || $player['actions'] == "error")
        continue;

      $spa = $this->players[$player['player_id']]['actions'];

      $this->players[$player['player_id']]['apm'] = count($spa);

      $ni = 30000;
      $ii = 0;
      $apm = 0;
      $astr = '';
      $sum = 0;
      foreach ($spa as $atime)
      {
        $sum += strlen($atime);
        if ($atime < $ni)
        {
          $ii++;
          $apm++;
        }
        else
        {
          while ($atime > $ni)
          {
            $astr.=',' . $ii;
            $ii = 0;
            $ni+=30000;
          }
        }
      }
      $this->players[$player['player_id']]['actions'] = substr($astr, 1);
    }

    // splitting teams
    foreach ($this->players as $player_id => $info)
    {
      if (isset($info['team']))
      { // to eliminate zombie-observers caused by Waaagh!TV
        $this->teams[$info['team']][$player_id] = $info;
      }
    }
  }

  /**
   * Helper Functions for Replay Parse Protocol
   */

  /**
   * Converts the player's color to the proper Dota ID
   * @param Player's color
   */
  protected function _getDotaId($color)
  {
    switch ($color)
    {
      case "blue":
        return 1;
      case "teal":
        return 2;
      case "purple":
        return 3;
      case "yellow":
        return 4;
      case "orange":
        return 5;
      case "pink":
        return 6;
      case "gray":
        return 7;
      case "lightblue":
        return 8;
      case "darkgreen":
        return 9;
      case "brown":
        return 10;
      default:
        return 0;
    }
  }

  /**
   * Converts the internal Dota ID mapping to the Game one
   * assuming that each team has 6 IDs reserved, team 2 starts
   * at ID 7 internally, but maps it to 6 as far as the players
   * game is concerned
   *
   * @param Internal DOTA ID (1-12)
   */
  protected function _getGameDotaId($internalDotaID)
  {
    switch ($internalDotaID)
    {
      // Team 1
      case 1:
        return 1;
      case 2:
        return 2;
      case 3:
        return 3;
      case 4:
        return 4;
      case 5:
        return 5;
      // Team 2
      case 7:
        return 6;
      case 8:
        return 7;
      case 9:
        return 8;
      case 10:
        return 9;
      case 11:
        return 10;
      default:
        return 0;
    }
  }

  /**
   * Returns the appropriate Entity object from the previously parsed XML Data.
   * @param EntityID
   */
  protected function _convertItemId($value)
  {
    $xmlParser = $this->getXmlMapData();

    if (!isset($xmlParser->hashMap[$value]))
    {
      $this->_log("Unknown ID: [" . $value . "]. \r\n");
    }
    else
    {
      $this->_log("Known ID: [" . $value . "]. \r\n");
    }

    if (empty($value) || !isset($xmlParser->hashMap[$value]))
    {
      return false;
    }

    return $xmlParser->hashMap[$value];
  }

  protected function _convertBool($value)
  {
    switch ($value)
    {
      case 0x00: $value = false;
        break;
      default: $value = true;
    }
    return $value;
  }

  protected function _convertSpeed($value)
  {
    switch ($value)
    {
      case 0: $value = $this->_t('Slow');
        break;
      case 1: $value = $this->_t('Normal');
        break;
      case 2: $value = $this->_t('Fast');
        break;
    }
    return $value;
  }

  protected function _convertVisibility($value)
  {
    switch ($value)
    {
      case 0: $value = $this->_t('Hide Terrain');
        break;
      case 1: $value = $this->_t('Map Explored');
        break;
      case 2: $value = $this->_t('Always Visible');
        break;
      case 3: $value = $this->_t('Default');
        break;
    }
    return $value;
  }

  protected function _convertObservers($value)
  {
    switch ($value)
    {
      case 0: $value = $this->_t('No Observers');
        break;
      case 2: $value = $this->_t('Observers on Defeat');
        break;
      case 3: $value = $this->_t('Full Observers');
        break;
      case 4: $value = $this->_t('Referees');
        break;
    }
    return $value;
  }

  protected function _convertGameType($value)
  {
    switch ($value)
    {
      case 0x01: $value = $this->_t('Ladder 1vs1/FFA');
        break;
      case 0x09: $value = $this->_t('Custom game');
        break;
      case 0x0D: $value = $this->_t('Single player/Local game');
        break;
      case 0x20: $value = $this->_t('Ladder team game (AT/RT)');
        break;
      default: $value = $this->_t('unknown');
    }
    return $value;
  }

  protected function _convertColor($value)
  {
    switch ($value)
    {
      case 0: $value = 'red';
        break;
      case 1: $value = 'blue';
        break;
      case 2: $value = 'teal';
        break;
      case 3: $value = 'purple';
        break;
      case 4: $value = 'yellow';
        break;
      case 5: $value = 'orange';
        break;
      case 6: $value = 'green';
        break;
      case 7: $value = 'pink';
        break;
      case 8: $value = 'gray';
        break;
      case 9: $value = 'lightblue';
        break;
      case 10: $value = 'darkgreen';
        break;
      case 11: $value = 'brown';
        break;
      case 12: $value = 'observer';
        break;
    }
    return $value;
  }

  protected function _convertRace($value)
  {
    switch ($value)
    {
      case 'ewsp': case 0x04: case 0x44: $value = $this->_t('Sentinel');
        break;
      case 'uaco': case 0x08: case 0x48: $value = $this->_t('Scourge');
        break;
      default: $value = 0; // do not change this line
    }
    return $value;
  }

  protected function _convertAi($value)
  {
    switch ($value)
    {
      case 0x00: $value = $this->_t("Easy");
        break;
      case 0x01: $value = $this->_t("Normal");
        break;
      case 0x00: $value = $this->_t("Insane");
        break;
    }
    return $value;
  }

  protected function _convertSelectMode($value)
  {
    switch ($value)
    {
      case 0x00: $value = $this->_t("Team & race selectable");
        break;
      case 0x01: $value = $this->_t("Team not selectable");
        break;
      case 0x03: $value = $this->_t("Team & race not selectable");
        break;
      case 0x04: $value = $this->_t("Race fixed to random");
        break;
      case 0xcc: $value = $this->_t("Automated Match Making (ladder)");
        break;
    }
    return $value;
  }

  protected function _convertChatMode($value)
  {
    switch ($value)
    {
      case 0x00: $value = 0;
        break;   //All
      case 0x01: $value = 1;
        break;   //Allies
      case 0x02: $value = 2;
        break;   //Observers
      case 0xFE: $value = 3;
        break;   //The game has been paused.
      case 0xFF: $value = 4;
        break;   //The game has been resumed.
      default: $value -= 2;           // this is for private messages
    }
    return $value;
  }

  protected function _convertBuildingId($value)
  {
    // non-ASCII ItemIDs
    if (ord($value{0}) < 0x41 || ord($value{0}) > 0x7A)
    {
      return 0;
    }

    switch ($value)
    {
      case 'halt': $value = $this->_t('Altar of Kings');
        break;
      case 'harm': $value = $this->_t('Workshop');
        break;
      case 'hars': $value = $this->_t('Arcane Sanctum');
        break;
      case 'hbar': $value = $this->_t('Barracks');
        break;
      case 'hbla': $value = $this->_t('Blacksmith');
        break;
      case 'hhou': $value = $this->_t('Farm');
        break;
      case 'hgra': $value = $this->_t('Gryphon Aviary');
        break;
      case 'hwtw': $value = $this->_t('Scout Tower');
        break;
      case 'hvlt': $value = $this->_t('Arcane Vault');
        break;
      case 'hlum': $value = $this->_t('Lumber Mill');
        break;
      case 'htow': $value = $this->_t('Town Hall');
        break;

      case 'etrp': $value = $this->_t('Ancient Protector');
        break;
      case 'etol': $value = $this->_t('Tree of Life');
        break;
      case 'edob': $value = $this->_t("Hunter's Hall");
        break;
      case 'eate': $value = $this->_t('Altar of Elders');
        break;
      case 'eden': $value = $this->_t('Ancient of Wonders');
        break;
      case 'eaoe': $value = $this->_t('Ancient of Lore');
        break;
      case 'eaom': $value = $this->_t('Ancient of War');
        break;
      case 'eaow': $value = $this->_t('Ancient of Wind');
        break;
      case 'edos': $value = $this->_t('Chimaera Roost');
        break;
      case 'emow': $value = $this->_t('Moon Well');
        break;

      case 'oalt': $value = $this->_t('Altar of Storms');
        break;
      case 'obar': $value = $this->_t('Barracks');
        break;
      case 'obea': $value = $this->_t('Beastiary');
        break;
      case 'ofor': $value = $this->_t('War Mill');
        break;
      case 'ogre': $value = $this->_t('Great Hall');
        break;
      case 'osld': $value = $this->_t('Spirit Lodge');
        break;
      case 'otrb': $value = $this->_t('Orc Burrow');
        break;
      case 'orbr': $value = $this->_t('Reinforced Orc Burrow');
        break;
      case 'otto': $value = $this->_t('Tauren Totem');
        break;
      case 'ovln': $value = $this->_t('Voodoo Lounge');
        break;
      case 'owtw': $value = $this->_t('Watch Tower');
        break;

      case 'uaod': $value = $this->_t('Altar of Darkness');
        break;
      case 'unpl': $value = $this->_t('Necropolis');
        break;
      case 'usep': $value = $this->_t('Crypt');
        break;
      case 'utod': $value = $this->_t('Temple of the Damned');
        break;
      case 'utom': $value = $this->_t('Tomb of Relics');
        break;
      case 'ugol': $value = $this->_t('Haunted Gold Mine');
        break;
      case 'uzig': $value = $this->_t('Ziggurat');
        break;
      case 'ubon': $value = $this->_t('Boneyard');
        break;
      case 'usap': $value = $this->_t('Sacrificial Pit');
        break;
      case 'uslh': $value = $this->_t('Slaughterhouse');
        break;
      case 'ugrv': $value = $this->_t('Graveyard');
        break;

      default: $value = 0;
    }
    return $value;
  }

  protected function _convertAction($value)
  {
    switch ($value)
    {
      case 'rightclick': $value = $this->_t('Right click');
        break;
      case 'select': $value = $this->_t('Select / deselect');
        break;
      case 'selecthotkey': $value = $this->_t('Select group hotkey');
        break;
      case 'assignhotkey': $value = $this->_t('Assign group hotkey');
        break;
      case 'ability': $value = $this->_t('Use ability');
        break;
      case 'basic': $value = $this->_t('Basic commands');
        break;
      case 'buildtrain': $value = $this->_t('Build / train');
        break;
      case 'buildmenu': $value = $this->_t('Enter build submenu');
        break;
      case 'heromenu': $value = $this->_t('Enter hero\'s abilities submenu');
        break;
      case 'subgroup': $value = $this->_t('Select subgroup');
        break;
      case 'item': $value = $this->_t('Give item / drop item');
        break;
      case 'removeunit': $value = $this->_t('Remove unit from queue');
        break;
      case 'esc': $value = $this->_t('ESC pressed');
        break;
    }
    return $value;
  }

  protected function _convertCol2($value)
  {
    if ($value < 6)
    {
      return $value - 1;
    }
    else
    {
      return $value - 2;
    }
  }

  protected function _convertTime($value)
  {
    $output = sprintf('%02d', intval($value / 60000)) . ':';
    $value = $value % 60000;
    $output .= sprintf('%02d', intval($value / 1000));

    return $output;
  }

  protected function _convertYesNo($value)
  {
    switch ($value)
    {
      case 0x00: $value = $this->_t('No');
        break;
      default: $value = $this->_t('Yes');
    }
    return $value;
  }

}

/**
 * Class for handling activated / used Heroes
 */
class ActivatedHero
{
  const DUPLICATE_SKILLING_TIME_LIMIT = 200;

  /**
   *
   * @var Dota_Replay_Parser_Entity_Hero
   */
  protected $_hero;
  protected $_skills = array();
  // Used to for evading duplicated actions
  protected $_lastSkilledTime = 0;
  // Used for limiting skill levels
  protected $_limitStats = 0;

  /**
   * Constructor
   * @param mixed $heroData
   */
  function __construct($heroData)
  {
    $this->_hero = $heroData;
  }

  /**
   * Add learned skill to hero
   *
   * @param mixed $skill - Skill Data
   * @param mixed $time - Time learned ( non converted in miliseconds )
   */
  function setSkill($skill, $time)
  {

    // TODO - Handle duplication / Level limit / Skill limit etc
    // Level limit Common skills A0NR and Aamk
    if ($this->_limitStats >= 10 && ($skill->getId() == "Aamk" || $skill->getId() == "A0NR"))
    {
      return;
    }

    // Handling duplication
    if ($time - $this->_lastSkilledTime < self::DUPLICATE_SKILLING_TIME_LIMIT)
      return;

    $this->_lastSkilledTime = $time;

    // Limit learned skills to 25
    if (count($this->_skills) >= 25)
    {
      return;
    }

    // Add skill
    $this->_skills[$time] = $skill;

    if ($skill['id'] == "Aamk" || $skill['id'] == "A0NR")
    {
      $this->_limitStats++;
    }
  }

  /**
   * @return Skills[time in miliseconds] = XML Skill data
   */
  function getSkills()
  {
    return $this->_skills;
  }

  /**
   * Get Hero ID
   * @return String Hero ID
   */
  function getId()
  {
    return $this->_hero['id'];
  }

  function getName()
  {
    return $this->_hero['name'];
  }

  /**
   * Return XML data for Hero
   * @return data - XML Data for Hero
   */
  function getData()
  {
    return $this->_hero;
  }

  /**
   * @return int Level
   */
  function getLevel()
  {
    return count($this->_skills);
  }

}

/**
 * Class for storing Player's end game statistics
 */
class PlayerStats
{

  public $heroKills;
  public $deaths;
  public $creepKills;
  public $creepDenies;
  public $assists;
  public $endGold;
  public $neutrals;
  public $inventory = array();      // Array with 6 elements, for the 6 inventory slots
  public $AA_Total;
  public $AA_Hit;
  public $HA_Total;
  public $HA_Hit;
  private $_pid;
  private $delayedSkills = array();
  // Dota broadcasts hero levels, so this is used to check for skill duplications and similiar incidents.
  private $levelCap = 1;
  /**
   * Class
   * @var ActivatedHero
   */
  private $hero = false;

  /**
   * Constructor
   * @param Player's ID
   */
  function __construct($PID)
  {
    $this->_pid = $PID;
  }

  /**
   *
   * Set Hero
   * @param mixed Hero - ActivatedHero class
   */
  function setHero($hero)
  {
    $this->hero = $hero;
  }

  /**
   * Return hero data - ActivatedHero class
   */
  function getHero()
  {
    if (!isset($this->hero))
    {
      return false;
    }
    return $this->hero;
  }

  /**
   * Get the current level cap
   * @returns Integer - Current level cap
   */
  function getLevelCap()
  {
    return $this->levelCap;
  }

  /**
   * Set the level cap
   * @param $levelCap - Level cap to set
   */
  function setLevelCap($levelCap)
  {
    $this->levelCap = $levelCap;
  }

  /**
   * @return boolean TRUE if hero is set, FALSE otherwise
   */
  function isSetHero()
  {
    if ($this->hero === false)
    {
      return false;
    }
    return true;
  }

  /**
   * Queue up skills, to be added later
   *
   * @param mixed $skill_data Skill Data
   * @param mixed $time Replay time in miliseconds
   * @param String $heroId Hero ID
   */
  function addDelayedSkill($skill_data, $time, $heroId)
  {
    $this->delayedSkills[] = array('skill_data' => $skill_data, 'time' => $time, 'heroId' => $heroId);
  }

  /**
   * Process delayed skills
   *
   */
  function processDelayedSkills()
  {
    if (count($this->delayedSkills) > 0)
    {

      foreach ($this->delayedSkills as $element)
      {
        // if ( !is_object($this->Hero) ) continue;

        if (is_object($this->hero) && $this->hero->getId() == $element['heroId'])
        {
          $this->hero->setSkill($element['skill_data'], $element['time']);
        }
        // TODO: Otherwise added the skill to the appropriate activated hero
      }
    }
  }

}
