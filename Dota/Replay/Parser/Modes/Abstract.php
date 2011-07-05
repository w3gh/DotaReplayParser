<?php
/*
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * Abstract class for DotA Game Modes
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
abstract class Dota_Replay_Parser_Modes_Abstract
{

  protected $shortName;
  protected $fullName;

  function getShortName()
  {
    return $this->shortName;
  }

  function getFullName()
  {
    return $this->fullName;
  }

}

