# How To Use

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

        $replay = new Dota_Replay($filename, $options);
        $replay->toArray();