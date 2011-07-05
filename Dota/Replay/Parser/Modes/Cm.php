<?php
/*
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * @see Dota_Replay_Parser_Modes_Abstract
 */
require_once 'Dota/Replay/Parser/Modes/Abstract.php';

/**
 * Captains mode
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
class Dota_Replay_Parser_Modes_Cm extends Dota_Replay_Parser_Modes_Abstract
{

  private $bansPerTeam = 3;
  private $heroBans = array();
  private $heroPicks = array();
  protected $shortName = 'cm';
  protected $fullName = "Captain's mode";

  /**
   * Get bans per team
   * @return Number of hero bans per team
   */
  public function getBansPerTeam()
  {
    return $this->bansPerTeam;
  }

  /**
   * Collecting bans
   * @param Entity $hero
   */
  function addHeroToBans($hero)
  {
    $this->heroBans[] = $hero;
  }

  /**
   * Returns current amount of banned heroes
   * @returns int Num of bans
   */
  function getNumBanned()
  {
    return count($this->heroBans);
  }

  /**
   * Collecting picks
   * @param Entity $hero
   */
  function addHeroToPicks($hero)
  {
    $this->heroPicks[] = $hero;
  }

  /**
   * Returns current amount of picked heroes
   * @returns int Num of picks
   */
  function getNumPicked()
  {
    return count($this->heroPicks);
  }

  /**
   * Get an array of bans
   * @returns Array of Hero Entity type bans
   */
  function getBans()
  {
    return $this->heroBans;
  }

  /**
   * Get an array of picks
   * @returns Array of Hero Entity type picks
   */
  function getPicks()
  {
    return $this->heroPicks;
  }

  /**
   * Returns TRUE if number of banned heroes equals or exceeds the set bansPerTeam
   * @returns Boolean True - Ban Phase Complete or False
   */
  function banPhaseComplete()
  {
    if (count($this->heroBans) >= ($this->bansPerTeam * 2))
    {
      return true;
    }
    return false;
  }

  /**
   * Sets the amount of bans per team
   *
   * @param mixed $num Bans per team
   */
  function setBansPerTeam($num)
  {
    $this->bansPerTeam = $num;
  }

}

