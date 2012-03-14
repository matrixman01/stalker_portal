<?php

namespace Stalker\Lib\OAuth;

use \Mysql;
use \Stalker\Lib\RESTAPI\v2\RESTApiRequest;
use \Stalker\Lib\HTTP\HTTPRequest;

class AuthAccessHandler extends AccessHandler
{
    private $token_expire = 86400;

    public function checkUserAuth($username, $password){
        sleep(1); // anti brute-force delay
        $user = Mysql::getInstance()->from('users')->where(array('login' => $username, 'password' => $password))->get()->first();
        return !empty($user);
    }

    public function generateUniqueToken($username){
        $user  = Mysql::getInstance()->from('users')->where(array('login' => $username))->get()->first();
        $token = $user['id'].'.'.md5(microtime(1));

        $token_record = Mysql::getInstance()->from('access_tokens')->where(array('uid' => $user['id']))->get()->first();

        $data = array(
            'uid'     => $user['id'],
            'token'   => $token,
            'secret_key'     => md5($token.microtime(1)),
            'started' => 'NOW()',
            'expires' => 'FROM_UNIXTIME(UNIX_TIMESTAMP(NOW())+'.$this->token_expire.')'
        );

        if (empty($token_record)){
            $result = Mysql::getInstance()->insert('access_tokens', $data)->insert_id();
        }else{
            $result = Mysql::getInstance()->update('access_tokens', $data, array('uid' => $user['id']));
        }

        if (!$result){
            return false;
        }

        return $token;
    }

    public function getSecretKey($username){
        $user  = Mysql::getInstance()->from('users')->where(array('login' => $username))->get()->first();

        return Mysql::getInstance()->from('access_tokens')->where(array('uid' => $user['id']))->get()->first('secret_key');
    }

    public function isValidClient($client_id, $client_secret){
        $client = Mysql::getInstance()->from('clients')->where(array('id' => $client_id, 'secret' => $client_secret, 'active' => 1))->get()->first();
        return !empty($client);
    }

    public function isClient($client_id){
        $client = Mysql::getInstance()->from('clients')->where(array('id' => $client_id, 'active' => 1))->get()->first();
        return !empty($client);
    }

    public function getUserId($username){
        return (int) Mysql::getInstance()->from('users')->where(array('login' => $username))->get()->first('id');
    }

    public function getAdditionalParams($username){
        return array(
            'user_id'    => $this->getUserId($username),
            'expires_in' => $this->token_expire
        );
    }

    public function getAccessSessionByToken($token){
        return Mysql::getInstance()->from('access_tokens')->where(array('token' => $token, 'expires>' => 'NOW()'))->get()->first();
    }

    public function setTimeDeltaForToken($token, $delta){
        $session = $this->getAccessSessionByToken($token);

        if (empty($session['time_delta'])){
            Mysql::getInstance()->update('access_tokens', array('time_delta' => $delta), array('token' => $token));
        }
    }

    public function getAccessSessionByDeveloperApiKey($key){
        return Mysql::getInstance()->from('developer_api_key')->where(array('api_key' => $key, 'expires>' => 'NOW()'))->get()->first();
    }

    public static function getAccessSchema(HTTPRequest $request){

        $auth_header = $request->getAuthorization();

        if (empty($auth_header) && $request->getParam('api_key') === null){
            throw new AuthUnauthorized("Authorization required");
        }

        if (strpos($auth_header, "MAC ") === 0 && \Config::getSafe('api_v2_access_type', 'bearer') == 'mac'){
            return new MACAccessType($request, new self);
        }else if (strpos($auth_header, "Bearer ") === 0 && \Config::getSafe('api_v2_access_type', 'bearer') == 'bearer'){
            return new BearerAccessType($request, new self);
        }else if ($request->getParam('api_key') !== null){
            return new DeveloperAccessType($request, new self);
        }else{
            throw new AuthBadRequest("Unsupported authentication type");
        }
    }
}

class AuthBadRequest extends \Exception{}

class AuthForbidden extends \Exception{}

class AuthUnauthorized extends \Exception{}