<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class TS3Client
{

    private $init = array();

    public function __construct($apikey, $host = 'localhost', $port = '25639', $timeout = '2')
    {
		if(is_null($apikey)){
			$this->addDebugLog('Error: apikey arg is required!');
		}

        return $this->init = ([
			'socket' => null,
            'apikey' => $apikey,
            'address' => $host,
            'port' => $port,
			'timeout' => $timeout,
			'authed' => false,
			'debug' => array(),
			'connected' => false,
			'user_clid' => '',
        ]);
	}
	
	function __call($name, $arg) {
		$this->addDebugLog('Method '.$name.' doesn\'t exist', $name, 0);
		return $this->generateOutput(false, array('Method '.$name.' doesn\'t exist'), false);
	}

	function __toString()
    {
		$this->addDebugLog('Class cannot be converted to string');
		return $this->generateOutput(false, array('Class cannot be converted to string'), false);
	}
	
	public function __invoke()
    {
        $this->addDebugLog('Class cannot be called as function');
		return $this->generateOutput(false, array('Class cannot be called as function'), false);
    }

    private function generateOutput($success, $errors, $data)
    {
        return array('success' => $success, 'errors' => $errors, 'data' => $data);
    }

    public function connect()
    {

        $socket = @fsockopen($this->init['address'], $this->init['port'], $errnum, $errstr, $this->init['timeout']);

        if (!$socket) {
            $this->addDebugLog('Error: connection failed!');
            return $this->generateOutput(false, array('Error: connection failed!', 'Client returns: ' . $errstr), false);
        } else {
            if (strpos(fgets($socket), 'TS3 Client') !== false) {
				$this->init['socket'] = $socket;
				if($this->auth())
				{
					$this->init['authed'] = true;

					if($this->succeeded($whoami = $this->whoami())){
						
					}
				}
                return $this->generateOutput(true, array(), true);
            } else {
                $this->addDebugLog('Error: there is no TS3 telnet interface running, check if TS3 ClientQuery plugin is running.');
                return $this->generateOutput(false, array('Error: there is no TS3 telnet interface running, check if TS3 ClientQuery plugin is running.'), false);
            }
        }
	}
	
	private function auth(){
		return $this->getData('boolean', ('auth apikey='.$this->init['apikey'].''));
	}

	public function getElement($element, $array) {
		return $array[$element];
	}

	public function succeeded($array) {
		if(isset($array['success'])) {
			return $array['success'];
		}else{
			return false;
		}
	}

	function execCommand($command)
    {
		if(!$this->isConnected()) {
			$this->addDebugLog('TS3 Client script isnt connected to telnet service');
			return $this->generateOutput(false, array('Error: script isnt connected to client'), false);
		}
		
		$data = '';

		$splittedCommand = str_split($command, 1024);
		
		$splittedCommand[(count($splittedCommand) - 1)] .= "\n";
		
		foreach($splittedCommand as $commandPart)
		{
			if(!(@fputs($this->init['socket'], $commandPart)))
			{
				$this->init['socket'] = $this->init['client_id'] = '';
				$this->addDebugLog('Socket closed.');
				return $this->generateOutput(false, array('Socket closed.'), false);
			}
		}

		do {

			$data .= @fgets($this->init['socket'], 4096);
			
			if(empty($data))
			{
				$this->init['socket'] = $this->init['client_id'] = '';
				$this->addDebugLog('Socket closed.');
				return $this->generateOutput(false, array('Socket closed.'), false);
			}
				
		} while(strpos($data, 'msg=') === false or strpos($data, 'error id=') === false);

		if(strpos($data, 'error id=0 msg=ok') === false) {
			$splittedResponse = explode('error id=', $data);
			$chooseEnd = count($splittedResponse) - 1;
			
			$cutIdAndMsg = explode(' msg=', $splittedResponse[$chooseEnd]);	
	
			return $this->generateOutput(false, array('ErrorID: '.$cutIdAndMsg[0].' | Message: '.$this->unEscapeText($cutIdAndMsg[1])), false);
		}else{
			return $this->generateOutput(true, array(), $data);
		}
	}

	private function getData($mode, $command) {
	
		$validModes = array('boolean', 'array', 'multi', 'plain');
	
		if($this->init['authed'] != true and strpos($command, 'auth') === false){
			$this->addDebugLog('Client didnt auth, check your login key.');
			return $this->generateOutput(false, array('Error: Client didnt auth, check your login key.'), false);
		}

		if(!in_array($mode, $validModes)) {
			$this->addDebugLog($mode.' is an invalid mode');
			return $this->generateOutput(false, array('Error: '.$mode.' is an invalid mode'), false);
		}
		
		if(empty($command)) {
			$this->addDebugLog('you have to enter a command');
			return $this->generateOutput(false, array('Error: you have to enter a command'), false);
		}
		
		$fetchData = $this->execCommand($command);
		
		
		$fetchData['data'] = str_replace(array('error id=0 msg=ok', chr('01')), '', $fetchData['data']);
		
		
		if($fetchData['success']) {
			if($mode == 'boolean') {
				return $this->generateOutput(true, array(), true);
			}
			
			if($mode == 'array') {
				if(empty($fetchData['data'])) { return $this->generateOutput(true, array(), array()); }
				$datasets = explode(' ', $fetchData['data']);
				
				$output = array();
				
				foreach($datasets as $dataset) {
					$dataset = explode('=', $dataset);
					
					if(count($dataset) > 2) {
						for($i = 2; $i < count($dataset); $i++) {
							$dataset[1] .= '='.$dataset[$i];
						}
						$output[$this->unEscapeText($dataset[0])] = $this->unEscapeText($dataset[1]);
					}else{
						if(count($dataset) == 1) {
							$output[$this->unEscapeText($dataset[0])] = '';
						}else{
							$output[$this->unEscapeText($dataset[0])] = $this->unEscapeText($dataset[1]);
						}
						
					}
				}
				return $this->generateOutput(true, array(), $output);
			}
			if($mode == 'multi') {
				if(empty($fetchData['data'])) { return $this->generateOutput(true, array(), array()); }
				$datasets = explode('|', $fetchData['data']);
				
				$output = array();
				
				foreach($datasets as $datablock) {
					$datablock = explode(' ', $datablock);
					
					$tmpArray = array();
					
					foreach($datablock as $dataset) {
						$dataset = explode('=', $dataset);
						if(count($dataset) > 2) {
							for($i = 2; $i < count($dataset); $i++) {
								$dataset[1] .= '='.$dataset[$i];
							}
							$tmpArray[$this->unEscapeText($dataset[0])] = $this->unEscapeText($dataset[1]);
						}else{
							if(count($dataset) == 1) {
								$tmpArray[$this->unEscapeText($dataset[0])] = '';
							}else{
								$tmpArray[$this->unEscapeText($dataset[0])] = $this->unEscapeText($dataset[1]);
							}
						}					
					}
					$output[] = $tmpArray;
				}
				return $this->generateOutput(true, array(), $output);
			}
			if($mode == 'plain') {
				return $fetchData;
			}
		}else{
			return $this->generateOutput(false, $fetchData['errors'], false);
		}
	}


	public function isConnected(){
		return $this->init['socket'] !== null ? 'true' : 'false';
	}

	private function escapeText($text) {
		
		$text = str_replace("\t", '\t', $text);
		$text = str_replace("\v", '\v', $text);
		$text = str_replace("\r", '\r', $text);
		$text = str_replace("\n", '\n', $text);
		$text = str_replace("\f", '\f', $text);
		$text = str_replace(' ', '\s', $text);
		$text = str_replace('|', '\p', $text);
		$text = str_replace('/', '\/', $text);

	  	return $text;
   }

	private function unEscapeText($text) {
		$escapedChars = array("\t", "\v", "\r", "\n", "\f", "\s", "\p", "\/");
		$unEscapedChars = array('', '', '', '', '', ' ', '|', '/');
	   	$text = str_replace($escapedChars, $unEscapedChars, $text);
		return $text;
   }

    private function addDebugLog($text, $function = '', $line = '')
    {
        if (empty($function) || empty($line)) {
            $backtrace = debug_backtrace();
            $function = $backtrace[1]['function'];
            $line = $backtrace[0]['line'];
        }
        $this->init['debug'][] = 'Error in ' . $function . '() on line ' . $line . ': ' . $text;
    }

    public function dumpLogs()
    {
        return array_key_exists('debug', $this->init) ? $this->init['debug'] : '';
	}

	public function banAddByIp($ip, $time = 0, $banreason = NULL) {
	
		if(!empty($banreason)) { $msg = ' banreason='.$this->escapeText($banreason); } else { $msg = NULL; }

		return $this->getData('array', 'banadd ip='.$ip.' time='.$time.$msg);
	}

	public function banAddByUid($uid, $time = 0, $banreason = NULL) {
		
		if(!empty($banreason)) { $msg = ' banreason='.$this->escapeText($banreason); } else { $msg = NULL; }
		
		return $this->getData('array', 'banadd uid='.$uid.' time='.$time.$msg);
	}

	public function banAddByName($name, $time = 0, $banreason = NULL) {
		
		if(!empty($banreason)) { $msg = ' banreason='.$this->escapeText($banreason); } else { $msg = NULL; }
										
		return $this->getData('array', 'banadd name='.$this->escapeText($name).' time='.$time.$msg);
	}

	public function banClient($clid, $time = 0, $banreason = NULL) {
		
		if(!empty($banreason)) { $msg = ' banreason='.$this->escapeText($banreason); } else { $msg = ''; }
		
		$result = $this->getData('plain', 'banclient clid='.$clid.' time='.$time.$msg);
		
		if($result['success']) {
			return $this->generateOutput(true, $result['errors'], $this->splitBanIds($result['data']));
		}else{
			return $this->generateOutput(false, $result['errors'], false);
		}
	}

	public function banDeleteAll() {
		return $this->getData('boolean', 'bandelall');
	}

	function banDelete($banID) {
		return $this->getData('boolean', 'bandel banid='.$banID);
	}

	function banList() {		
		return $this->getData('plain', 'banlist');
	}

	
	function whoami() {
		return $this->getData('array', 'whoami');
	}

}

function test(...$array){
	return var_dump($array);
}

try{  //wrap around possible cause of error or notice

	$client = new TS3Client('1N9X-C83N-QJNI-BN2T-EJ4Y');
	$client->connect();

	var_dump($client->whoami());
	
  }catch(Exception $e){

    echo($e->getMessage());
  }
