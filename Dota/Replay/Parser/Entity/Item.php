<?php
/*
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * @see Dota_Replay_Parser_Entity_Abstract
 */
require_once 'Dota/Replay/Parser/Entity/Abstract.php';

/**
 * Item
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package 
 */
class Dota_Replay_Parser_Entity_Item extends Dota_Replay_Parser_Entity_Abstract
{
  public function  __construct($Name, $Art, $Comment, $Cost, $Id, $ProperNames, $RelatedTo, $Type)
  {
    parent::__construct($Name, $Art, $Comment, $Cost, $Id, $ProperNames, $RelatedTo, $Type);
  }
}

