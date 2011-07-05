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
 * Captain Draft
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
class Dota_Replay_Parser_Modes_Cd extends Dota_Replay_Parser_Modes_Abstract
{

  private $bansPerTeam = 2;
  private $heroPool = array();
  private $heroBans = array();
  private $heroPicks = array();
  protected $shortName = 'cd';
  protected $fullName = "Captain's draft";

  public function getBansPerTeam()
  {
    return $this->bansPerTeam;
  }

  /**
   * Collecting bans
   * @param Entity $hero
   */
  public function addHeroToBans($hero)
  {
    $this->heroBans[] = $hero;
  }

  /**
   * Collecting picks
   * @param Entity $hero
   */
  public function addHeroToPicks($hero)
  {
    $this->heroPicks[] = $hero;
  }

  /**
   *  Used to gather hero pool information
   * @param Entity $hero
   */
  public function addHeroToPool($hero)
  {
    $this->heroPool[] = $hero;
  }

  /**
   * Returns current amount of picked heroes
   * @returns int Num of picks
   */
  public function getNumPicked()
  {
    return count($this->heroPicks);
  }

  public function getBans()
  {
    return $this->heroBans;
  }

  public function getPicks()
  {
    return $this->heroPicks;
  }

}

