<?php
/**
 * @copyright 2017, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OC\AppFramework\Middleware\Security;

use OC\AppFramework\Http\Request;
use OC\AppFramework\Middleware\Security\Exceptions\LaxSameSiteCookieFailedException;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

class SameSiteCookieMiddleware extends Middleware {

	/** @var Request */
	private $request;

	/** @var ControllerMethodReflector */
	private $reflector;

	public function __construct(Request $request,
								ControllerMethodReflector $reflector) {
		$this->request = $request;
		$this->reflector = $reflector;
	}

	public function beforeController($controller, $methodName) {
		$requestUri = $this->request->getScriptName();
		$processingScript = explode('/', $requestUri);
		$processingScript = $processingScript[count($processingScript) - 1];

		if ($processingScript !== 'index.php') {
			return;
		}

		$noSSC = $this->reflector->hasAnnotation('NoSameSiteCookieRequired');
		if ($noSSC) {
			return;
		}

		if (!$this->request->passesLaxCookieCheck()) {
			throw new LaxSameSiteCookieFailedException();
		}
	}

	public function afterException($controller, $methodName, \Exception $exception) {
		if ($exception instanceof LaxSameSiteCookieFailedException) {
			$respone = new Response();
			$respone->setStatus(Http::STATUS_FOUND);
			$respone->addHeader('Location', $this->request->getRequestUri());

			$this->setSameSiteCookie();

			return $respone;
		}

		throw $exception;
	}

	protected function setSameSiteCookie() {
		$cookieParams = $this->request->getCookieParams();
		$secureCookie = ($cookieParams['secure'] === true) ? 'secure; ' : '';
		$policies = [
			'lax',
			'strict',
		];

		// Append __Host to the cookie if it meets the requirements
		$cookiePrefix = '';
		if ($cookieParams['secure'] === true && $cookieParams['path'] === '/') {
			$cookiePrefix = '__Host-';
		}

		foreach ($policies as $policy) {
			\ContextManager::setcookie(
				$cookiePrefix . 'nc_sameSiteCookie' . $policy,
				'true',
				[
					'httponly' => true,
					'expires' => 4102444800,
					'path' => $cookieParams['path'],
					'samesite' => $policy,
					'secure' => $cookieParams['secure'],
				]
			);
		}
	}
}
