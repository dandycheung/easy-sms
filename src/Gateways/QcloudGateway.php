<?php

/*
 * This file is part of the overtrue/easy-sms.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\EasySms\Gateways;

use GuzzleHttp\Psr7\Uri;
use Overtrue\EasySms\Contracts\MessageInterface;
use Overtrue\EasySms\Contracts\PhoneNumberInterface;
use Overtrue\EasySms\Exceptions\GatewayErrorException;
use Overtrue\EasySms\Support\Config;
use Overtrue\EasySms\Traits\HasHttpRequest;

/**
 * Class QcloudGateway.
 *
 * @see https://cloud.tencent.com/document/api/382/55981
 */
class QcloudGateway extends Gateway
{
    use HasHttpRequest;

    public const ENDPOINT_URL = 'https://sms.tencentcloudapi.com';

    public const ENDPOINT_SERVICE = 'sms';

    public const ENDPOINT_METHOD = 'SendSms';

    public const ENDPOINT_VERSION = '2021-01-11';

    public const ENDPOINT_REGION = 'ap-guangzhou';

    public const ENDPOINT_FORMAT = 'json';

    /**
     * @return array
     *
     * @throws GatewayErrorException ;
     */
    public function send(PhoneNumberInterface $to, MessageInterface $message, Config $config)
    {
        $data = $message->getData($this);

        $signName = !empty($data['sign_name']) ? $data['sign_name'] : $config->get('sign_name', '');

        unset($data['sign_name']);

        $phone = !\is_null($to->getIDDCode()) ? strval($to->getUniversalNumber()) : $to->getNumber();
        $params = [
            'PhoneNumberSet' => [
                $phone,
            ],
            'SmsSdkAppId' => $this->config->get('sdk_app_id'),
            'SignName' => $signName,
            'TemplateId' => (string) $message->getTemplate($this),
            'TemplateParamSet' => array_map('strval', array_values($data)),
        ];

        $time = time();

        $endpoint = $this->config->get('endpoint', self::ENDPOINT_URL);

        $result = $this->request('post', $endpoint, [
            'headers' => [
                'Authorization' => $this->generateSign($params, $time),
                'Host' => (new Uri($endpoint))->getHost(),
                'Content-Type' => 'application/json; charset=utf-8',
                'X-TC-Action' => self::ENDPOINT_METHOD,
                'X-TC-Region' => $this->config->get('region', self::ENDPOINT_REGION),
                'X-TC-Timestamp' => $time,
                'X-TC-Version' => self::ENDPOINT_VERSION,
            ],
            'json' => $params,
        ]);

        if (!empty($result['Response']['Error']['Code'])) {
            throw new GatewayErrorException($result['Response']['Error']['Message'], 400, $result);
        }

        if (!empty($result['Response']['SendStatusSet'])) {
            foreach ($result['Response']['SendStatusSet'] as $group) {
                if ('Ok' != $group['Code']) {
                    throw new GatewayErrorException($group['Message'], 400, $result);
                }
            }
        }

        return $result;
    }

    /**
     * Generate Sign.
     *
     * @param array $params
     *
     * @return string
     */
    protected function generateSign($params, $timestamp)
    {
        $date = gmdate('Y-m-d', $timestamp);
        $secretKey = $this->config->get('secret_key');
        $secretId = $this->config->get('secret_id');
        $endpoint = $this->config->get('endpoint', self::ENDPOINT_URL);
        $host = (new Uri($endpoint))->getHost();

        $canonicalRequest = 'POST'."\n".
            '/'."\n".
            ''."\n".
            'content-type:application/json; charset=utf-8'."\n".
            'host:'.$host."\n\n".
            'content-type;host'."\n".
            hash('SHA256', json_encode($params));

        $stringToSign =
            'TC3-HMAC-SHA256'."\n".
            $timestamp."\n".
            $date.'/'.self::ENDPOINT_SERVICE.'/tc3_request'."\n".
            hash('SHA256', $canonicalRequest);

        $secretDate = hash_hmac('SHA256', $date, 'TC3'.$secretKey, true);
        $secretService = hash_hmac('SHA256', self::ENDPOINT_SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('SHA256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('SHA256', $stringToSign, $secretSigning);

        return 'TC3-HMAC-SHA256'
            .' Credential='.$secretId.'/'.$date.'/'.self::ENDPOINT_SERVICE.'/tc3_request'
            .', SignedHeaders=content-type;host, Signature='.$signature;
    }
}
