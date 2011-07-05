<?php
/*
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @author Seven <zabkar@gmail.com>
 * @copyright Copyright &copy; 2010-2012 Nikolay Kostyurin, Seven, Julas, Rush4Hire, esby and Rachmadi
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * Abstract class for Items, Skills,
 *
 * @author Nikolay Kosturin <jilizart@gmail.com>
 * @version $Id:$
 * @package Dota_Replay
 */
abstract class Dota_Replay_Parser_Entity_Abstract implements ArrayAccess
{

  private $Name;
  private $Art;
  private $Comment;
  private $Cost;
  private $Id;
  private $Type;
  private $ProperNames;
  private $RelatedTo;
  private $_d = array();

  public function __construct($Name, $Art, $Comment, $Cost, $Id, $ProperNames, $RelatedTo, $Type)
  {
    $this->Name = $Name;
    $this->Art = $Art;
    $this->Cost = $Cost;
    $this->Id = $Id;
    $this->ProperNames = $ProperNames;
    $this->RelatedTo = $RelatedTo;
    $this->Type = $Type;
  }

  public function getName()
  {
    return $this->Name;
  }

  public function getArt()
  {
    return $this->Art;
  }

  public function getComment()
  {
    return $this->Comment;
  }

  public function getCost()
  {
    return $this->Cost;
  }

  public function getId()
  {
    return $this->Id;
  }

  public function getProperNames()
  {
    return $this->ProperNames;
  }

  public function getRelatedTo()
  {
    return $this->RelatedTo;
  }

  public function getEntityType()
  {
    return $this->Type;
  }

  /*
   * ArrayAccess Methods
   */

  public function offsetExists($offset)
  {
    $getter = 'get' . ucfirst($offset);
    if (method_exists($this, $getter))
      return (bool) $this->$getter();
    else
      return isset($this->_d[$key]) || array_key_exists($key, $this->_d);
  }

  public function offsetGet($offset)
  {
    $getter = 'get' . ucfirst($offset);
    if (method_exists($this, $getter))
      return $this->$getter();
    elseif (isset($this->_d[$key]))
      return $this->_d[$key];
    else
      return null;
  }

  public function offsetSet($offset, $value)
  {
    if ($offset === null)
      $this->_d[] = $value;
    else
      $this->_d[$offset] = $value;
  }

  public function offsetUnset($offset)
  {
    if (isset($this->_d[$offset]))
    {
      $value = $this->_d[$offset];
      unset($this->_d[$offset]);
      return $value;
    }
    else
    {
      // it is possible the value is null, which is not detected by isset
      unset($this->_d[$offset]);
      return null;
    }
  }

}

