<?php

namespace PushNotification\Push\Android;

use PushNotification\Model\FailureDevice;
use PushNotification\Model\GcmError;
use PushNotification\Push\DeviceManager;
use ZendService\Google\Gcm\Response;
use Sly\NotificationPusher\Adapter\Gcm as GcmAdapter;
use Sly\NotificationPusher\Model\Push;
use Sly\NotificationPusher\PushManager;

/**
 * Envia notificações a um Android
 */
class AndroidPushController {

	/**
	 * Chave da api do ANDROID para produção
	 *
	 * @var string
	 */
	const ANDROID_API_KEY_PROD = "AIzaSyApbMAGOln9XY4MgXFUD_RnqgoHv2jEt8M";

	/**
	 * Chave da api do ANDROID para desenvolvimento
	 *
	 * @var string
	 */
	const ANDROID_API_KEY_DEV = "AIzaSyApbMAGOln9XY4MgXFUD_RnqgoHv2jEt8M";

	/**
	 * Envia a notificação para dispositivos Android
	 *
	 * @param Device[] $devices
	 *        	Dispositivos
	 * @param Message $message
	 *        	Notificação
	 * @param NotificationResult $notificationResult
	 *        	Resultado do envio da notificação
	 * @param PushManager $pushManager
	 *        	Gerenciador de push
	 * @param string $environment
	 *        	Ambiente a ser utilizado, possíveis valores:
	 *        	PushManager::ENVIRONMENT_DEV, PushManager:ENVIRONMENT_PROD.
	 */
	public static function send($devices, $message, $notificationResult, $pushManager, $environment) {
		if (iterator_count($devices->getIterator()) > 0) {
			try {
				$apiKey = $environment == PushManager::ENVIRONMENT_PROD ? AndroidPushController::ANDROID_API_KEY_PROD
											: AndroidPushController::ANDROID_API_KEY_DEV;

				$gcmAdapter = new GcmAdapter(array(
						'apiKey' => $apiKey
				));

				// envia as notificações
				$push = new Push($gcmAdapter, $devices, $message);
				$pushManager->add($push);
				$pushManager->push();

				// obtém a resposta do envio do envio
				$response = $gcmAdapter->getResponse();

				// dispositivo que não receberam as notificações e motivo
				$failureResults = $response->getResult(Response::RESULT_ERROR);
				$failureDevices = AndroidPushController::handleFailureResult($failureResults);

				// dispositivo que tiveram seus identificadores modificados
				$canonicalResults = $response->getResult(Response::RESULT_CANONICAL);
				AndroidPushController::handleCanonicalResult($canonicalResults);

				$notificationResult->addDevicesNotNotified($failureDevices);
			} catch (\Exception $e) {
				$notificationResult->setAndroidFailed(true);
				$notificationResult->setAndroidFailureReason($e->getMessage());
			}
		}
	}

	/**
	 * Trata os erros ocorridos ao enviar a notificação para o Android
	 *
	 * @param object $results
	 *        	Resultados de falha
	 * @return FailureDevice Dispositivo contendo o motivo da falha no envio da notificação
	 */
	private static function handleFailureResult($results) {
		$obj = new \ArrayObject($results);
		$iterator = $obj->getIterator();
		$failureDevices = array();

		// itera sobre os resultados de falha para verificar o motivo,
		// remove o dispositivo se o token não for mais válido
		while ($iterator->valid()) {
			$token = $iterator->key();
			$reason = $iterator->current();

			if ($reason == GcmError::INVALID_REGISTRATION || $reason == GcmError::NOT_REGISTERED) {
				DeviceManager::deleteDevice($token);
			} else {
				$failureDevices[] = new FailureDevice($token, Device::ANDROID, $reason);
			}

			$iterator->next();
		}

		return $failureDevices;
	}

	/**
	 * Trata os resultados contendo dispositivos Android que tiveram seus identificadores atualizados
	 *
	 * @param object $results
	 *        	Resultados
	 * @param int $deviceType
	 *        	Tipo de dispositivo
	 */
	private static function handleCanonicalResult($results) {
		$obj = new \ArrayObject($results);
		$iterator = $obj->getIterator();

		// itera sobre os resultados para atualizar os identificadores dos dispositivos
		while ($iterator->valid()) {
			$oldToken = $iterator->key();
			$newToken = $iterator->current()->registrationId;

			DeviceManager::updateDevice($oldToken, $newToken);

			$iterator->next();
		}
	}
}