<?php

namespace Truonglv\Api\XF\Pub\Controller;

use XF;
use function md5;
use Truonglv\Api\App;
use function is_array;
use function hash_equals;
use Truonglv\Api\Entity\AccessToken;

class MiscController extends XFCP_MiscController
{
    public function actionTApiGoto()
    {
        $payload = $this->filter(App::KEY_LINK_PROXY_INPUT_DATA, 'str');
        $sign = $this->filter(App::KEY_LINK_PROXY_INPUT_SIGNATURE, 'str');
        if ($sign === '') {
            return $this->redirect($this->buildLink('index'));
        }

        $computeSign = md5($payload . $this->app()->options()->tApi_encryptKey);
        if (!hash_equals($sign, $computeSign)) {
            return $this->redirect($this->buildLink('index'));
        }

        $base64Decoded = \base64_decode($payload, true);
        if ($base64Decoded === false) {
            return $this->redirect($this->buildLink('index'));
        }

        $data = \GuzzleHttp\Utils::jsonDecode($base64Decoded, true);
        if (!is_array($data)) {
            return $this->redirect($this->buildLink('index'));
        }

        $targetUrl = $data[App::KEY_LINK_PROXY_TARGET_URL];
        if (!isset($data[App::KEY_LINK_PROXY_DATE])) {
            return $this->redirectPermanently($targetUrl);
        }

        $isActive = ($data[App::KEY_LINK_PROXY_DATE] + 1300) > XF::$time;

        if ($isActive) {
            $accessToken = $data[App::KEY_LINK_PROXY_ACCESS_TOKEN];
            $token = $this->em()->find(AccessToken::class, $accessToken);
            if ($token !== null && !$token->isExpired() && $token->User !== null) {
                $loginPlugin = $this->plugin(XF\ControllerPlugin\LoginPlugin::class);
                $loginPlugin->completeLogin($token->User, false);
            }
        }

        return $this->redirectPermanently($targetUrl);
    }
}
