<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Clark Tomlinson <fallen013@gmail.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Encryption\AppInfo;

use OC\Files\View;
use OCA\Encryption\Controller\RecoveryController;
use OCA\Encryption\Controller\SettingsController;
use OCA\Encryption\Controller\StatusController;
use OCA\Encryption\Crypto\Crypt;
use OCA\Encryption\Crypto\DecryptAll;
use OCA\Encryption\Crypto\EncryptAll;
use OCA\Encryption\Crypto\Encryption;
use OCA\Encryption\HookManager;
use OCA\Encryption\Hooks\UserHooks;
use OCA\Encryption\KeyManager;
use OCA\Encryption\Recovery;
use OCA\Encryption\Session;
use OCA\Encryption\Users\Setup;
use OCA\Encryption\Util;
use OCP\Encryption\IManager;
use OCP\IConfig;
use OCP\IServerContainer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Helper\QuestionHelper;

class Application extends \OCP\AppFramework\App {

	/** @var IManager */
	private $encryptionManager;
	/** @var IConfig */
	private $config;

	/**
	 * @param array $urlParams
	 */
	public function __construct($urlParams = []) {
		parent::__construct('encryption', $urlParams);
		$this->encryptionManager = \OC::$server->getEncryptionManager();
		$this->config = \OC::$server->getConfig();
		$this->registerServices();
	}

	public function setUp() {
		if ($this->encryptionManager->isEnabled()) {
			/** @var Setup $setup */
			$setup = $this->getContainer()->query(Setup::class);
			$setup->setupSystem();
		}
	}

	/**
	 * register hooks
	 */
	public function registerHooks() {
		if (!$this->config->getSystemValueBool('maintenance')) {
			$container = $this->getContainer();
			$server = $container->getServer();
			// Register our hooks and fire them.
			$hookManager = new HookManager();

			$hookManager->registerHook([
				new UserHooks($container->query(KeyManager::class),
					$server->getUserManager(),
					$server->getLogger(),
					$container->query(Setup::class),
					$server->getUserSession(),
					$container->query(Util::class),
					$container->query(Session::class),
					$container->query(Crypt::class),
					$container->query(Recovery::class))
			]);

			$hookManager->fireHooks();
		} else {
			// Logout user if we are in maintenance to force re-login
			$this->getContainer()->getServer()->getUserSession()->logout();
		}
	}

	public function registerEncryptionModule() {
		$container = $this->getContainer();


		$this->encryptionManager->registerEncryptionModule(
			Encryption::ID,
			Encryption::DISPLAY_NAME,
			function () use ($container) {
				return new Encryption(
				$container->query(Crypt::class),
				$container->query(KeyManager::class),
				$container->query(Util::class),
				$container->query(Session::class),
				$container->query(EncryptAll::class),
				$container->query(DecryptAll::class),
				$container->getServer()->getLogger(),
				$container->getServer()->getL10N($container->getAppName())
			);
			});
	}

	public function registerServices() {
		$container = $this->getContainer();

		$container->registerService(Crypt::class,	function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new Crypt($server->getLogger(),
					$server->getUserSession(),
					$server->getConfig(),
					$server->getL10N($c->get('AppName')));
		});

		$container->registerService(Session::class,	function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new Session($server->getSession());
		}
		);

		$container->registerService(KeyManager::class,	function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);

			return new KeyManager($server->getEncryptionKeyStorage(),
					$c->get(Crypt::class),
					$server->getConfig(),
					$server->getUserSession(),
					new Session($server->getSession()),
					$server->getLogger(),
					$c->get(Util::class),
					$server->getLockingProvider()
				);
		});

		$container->registerService(Recovery::class,		function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);

			return new Recovery(
					$server->getUserSession(),
					$c->get(Crypt::class),
					$c->get(KeyManager::class),
					$server->getConfig(),
					$server->getEncryptionFilesHelper(),
					new View());
		});

		$container->registerService(RecoveryController::class, function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new RecoveryController(
				$c->get('AppName'),
				$server->getRequest(),
				$server->getConfig(),
				$server->getL10N($c->get('AppName')),
				$c->get(Recovery::class));
		});

		$container->registerService(StatusController::class, function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new StatusController(
				$c->get('AppName'),
				$server->getRequest(),
				$server->getL10N($c->get('AppName')),
				$c->get(Session::class),
				$server->getEncryptionManager()
			);
		});

		$container->registerService(SettingsController::class, function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new SettingsController(
				$c->get('AppName'),
				$server->getRequest(),
				$server->getL10N($c->get('AppName')),
				$server->getUserManager(),
				$server->getUserSession(),
				$c->get(KeyManager::class),
				$c->get(Crypt::class),
				$c->get(Session::class),
				$server->getSession(),
				$c->get(Util::class)
			);
		});

		$container->registerService(Setup::class,	function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new Setup($server->getLogger(),
					$server->getUserSession(),
					$c->get(Crypt::class),
					$c->get(KeyManager::class));
		});

		$container->registerService(Util::class, function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);

			return new Util(
					new View(),
					$c->get(Crypt::class),
					$server->getLogger(),
					$server->getUserSession(),
					$server->getConfig(),
					$server->getUserManager());
		});

		$container->registerService(EncryptAll::class,	function (ContainerInterface $c) {
			/** @var IServerContainer $server */
			$server = $c->get(IServerContainer::class);
			return new EncryptAll(
					$c->get(Setup::class),
					$server->getUserManager(),
					new View(),
					$c->get(KeyManager::class),
					$c->get(Util::class),
					$server->getConfig(),
					$server->getMailer(),
					$server->getL10N('encryption'),
					new QuestionHelper(),
					$server->getSecureRandom()
				);
		}
		);

		$container->registerService(DecryptAll::class,function (ContainerInterface $c) {
			return new DecryptAll(
					$c->get(Util::class),
					$c->get(KeyManager::class),
					$c->get(Crypt::class),
					$c->get(Session::class),
					new QuestionHelper()
				);
		}
		);
	}
}
