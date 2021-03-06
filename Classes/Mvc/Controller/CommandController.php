<?php
namespace Helhum\Typo3Console\Mvc\Controller;

/*                                                                        *
 * This script belongs to the TYPO3 Flow framework.                       *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use Helhum\Typo3Console\Log\Writer\ConsoleWriter;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogLevel;
use Helhum\Typo3Console\Mvc\Cli\ConsoleOutput;
use TYPO3\CMS\Extbase\Mvc\Cli\Request;
use TYPO3\CMS\Extbase\Mvc\Cli\Response;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandControllerInterface;
use Symfony\Component\Console\Output\ConsoleOutput as SymfonyConsoleOutput;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\TableHelper;
use TYPO3\CMS\Extbase\Mvc\Controller\Arguments;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidArgumentTypeException;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchCommandException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Mvc\ResponseInterface;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;

/**
 * A controller which processes requests from the command line
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 */
class CommandController implements CommandControllerInterface {

	/**
	 * @var Request
	 */
	protected $request;

	/**
	 * @var Response
	 */
	protected $response;

	/**
	 * @var Arguments
	 */
	protected $arguments;

	/**
	 * @var ConsoleOutput
	 */
	protected $output;

	/**
	 * Name of the command method
	 *
	 * @var string
	 */
	protected $commandMethodName = '';

	/**
	 * Whether the command needs admin access to perform its job
	 *
	 * @var bool
	 * @api
	 */
	protected $requestAdminPermissions = FALSE;

	/**
	 * @var AbstractUserAuthentication
	 */
	protected $userAuthentication;

	/**
	 * @var \TYPO3\CMS\Extbase\Reflection\ReflectionService
	 * @inject
	 */
	protected $reflectionService;

	/**
	 * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
	 * @return void
	 */
	public function injectObjectManager(\TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager) {
		$this->objectManager = $objectManager;
		$this->arguments = $this->objectManager->get('TYPO3\\CMS\\Extbase\\Mvc\\Controller\\Arguments');
		$this->userAuthentication = isset($GLOBALS['BE_USER']) ? $GLOBALS['BE_USER'] : NULL;
		$this->output = $this->objectManager->get('Helhum\\Typo3Console\\Mvc\\Cli\\ConsoleOutput');
	}

	/**
	 * Checks if the current request type is supported by the controller.
	 *
	 * @param \TYPO3\CMS\Extbase\Mvc\RequestInterface $request The current request
	 * @return boolean TRUE if this request type is supported, otherwise FALSE
	 * @api
	 */
	public function canProcessRequest(\TYPO3\CMS\Extbase\Mvc\RequestInterface $request) {
		return $request instanceof Request;
	}

	/**
	 * Processes a command line request.
	 *
	 * @param RequestInterface $request The request object
	 * @param ResponseInterface $response The response, modified by this handler
	 * @return void
	 * @throws UnsupportedRequestTypeException if the controller doesn't support the current request type
	 * @api
	 */
	public function processRequest(RequestInterface $request, ResponseInterface $response) {
		if (!$request instanceof Request) {
			throw new UnsupportedRequestTypeException(sprintf('%s only supports command line requests – requests of type "%s" given.', get_class($this), get_class($request)), 1300787096);
		}

		$this->request = $request;
		$this->request->setDispatched(TRUE);
		$this->response = $response;

		$this->commandMethodName = $this->resolveCommandMethodName();
		$this->initializeCommandMethodArguments();
		$this->mapRequestArgumentsToControllerArguments();
		$this->callCommandMethod();
	}

	/**
	 * Resolves and checks the current command method name
	 *
	 * Note: The resulting command method name might not have the correct case, which isn't a problem because PHP is case insensitive regarding method names.
	 *
	 * @return string Method name of the current command
	 * @throws NoSuchCommandException
	 */
	protected function resolveCommandMethodName() {
		$commandMethodName = $this->request->getControllerCommandName() . 'Command';
		if (!is_callable(array($this, $commandMethodName))) {
			throw new NoSuchCommandException(sprintf('A command method "%s()" does not exist in controller "%s".', $commandMethodName, get_class($this)), 1300902143);
		}
		return $commandMethodName;
	}

	/**
	 * Initializes the arguments array of this controller by creating an empty argument object for each of the
	 * method arguments found in the designated command method.
	 *
	 * @return void
	 * @throws InvalidArgumentTypeException
	 */
	protected function initializeCommandMethodArguments() {
		$this->arguments->removeAll();
		$methodParameters = $this->reflectionService->getMethodParameters(get_class($this), $this->commandMethodName);

		foreach ($methodParameters as $parameterName => $parameterInfo) {
			$dataType = NULL;
			if (isset($parameterInfo['type'])) {
				$dataType = $parameterInfo['type'];
			} elseif ($parameterInfo['array']) {
				$dataType = 'array';
			}
			if ($dataType === NULL) {
				throw new InvalidArgumentTypeException(sprintf('The argument type for parameter $%s of method %s->%s() could not be detected.', $parameterName, get_class($this), $this->commandMethodName), 1306755296);
			}
			$defaultValue = (isset($parameterInfo['defaultValue']) ? $parameterInfo['defaultValue'] : NULL);
			$this->arguments->addNewArgument($parameterName, $dataType, ($parameterInfo['optional'] === FALSE), $defaultValue);
		}
	}

	/**
	 * Maps arguments delivered by the request object to the local controller arguments.
	 *
	 * @return void
	 */
	protected function mapRequestArgumentsToControllerArguments() {
		/** @var Argument $argument */
		foreach ($this->arguments as $argument) {
			$argumentName = $argument->getName();
			if ($this->request->hasArgument($argumentName)) {
				$argument->setValue($this->request->getArgument($argumentName));
				continue;
			}
			if (!$argument->isRequired()) {
				continue;
			}
			$argumentValue = NULL;
			$commandArgumentDefinition = $this->objectManager->get('TYPO3\\CMS\\Extbase\\Mvc\\Cli\\CommandArgumentDefinition', $argumentName, TRUE, NULL);
			while ($argumentValue === NULL) {
				$argumentValue = $this->output->ask(sprintf('<comment>Please specify the required argument "%s":</comment> ', $commandArgumentDefinition->getDashedName()));
			}
			$argument->setValue($argumentValue);
		}
	}

	/**
	 * Forwards the request to another command and / or CommandController.
	 *
	 * Request is directly transferred to the other command / controller
	 * without the need for a new request.
	 *
	 * @param string $commandName
	 * @param string $controllerObjectName
	 * @param array $arguments
	 * @return void
	 * @throws StopActionException
	 */
	protected function forward($commandName, $controllerObjectName = NULL, array $arguments = array()) {
		$this->request->setDispatched(FALSE);
		$this->request->setControllerCommandName($commandName);
		if ($controllerObjectName !== NULL) {
			$this->request->setControllerObjectName($controllerObjectName);
		}
		$this->request->setArguments($arguments);

		$this->arguments->removeAll();
		throw new StopActionException();
	}

	/**
	 * Calls the specified command method and passes the arguments.
	 *
	 * If the command returns a string, it is appended to the content in the
	 * response object. If the command doesn't return anything and a valid
	 * view exists, the view is rendered automatically.
	 *
	 * @return void
	 */
	protected function callCommandMethod() {
		$preparedArguments = array();
		/** @var Argument $argument */
		foreach ($this->arguments as $argument) {
			$preparedArguments[] = $argument->getValue();
		}
		$originalRole = $this->ensureAdminRoleIfRequested();
		$commandResult = call_user_func_array(array($this, $this->commandMethodName), $preparedArguments);
		$this->restoreUserRole($originalRole);
		if (is_string($commandResult) && $commandResult !== '') {
			$this->response->appendContent($commandResult);
		} elseif (is_object($commandResult) && method_exists($commandResult, '__toString')) {
			$this->response->appendContent((string) $commandResult);
		}
	}

	/**
	 * Set admin permissions for currently authenticated user if requested
	 * and returns the original state or NULL
	 *
	 * @return NULL|int
	 */
	protected function ensureAdminRoleIfRequested() {
		if (!$this->requestAdminPermissions || !$this->userAuthentication || !isset($this->userAuthentication->user['admin'])) {
			return NULL;
		}
		$originalRole = $this->userAuthentication->user['admin'];
		$this->userAuthentication->user['admin'] = 1;
		return $originalRole;
	}

	/**
	 * Restores the original user role
	 *
	 * @param NULL|int $originalRole
	 */
	protected function restoreUserRole($originalRole) {
		if ($originalRole !== NULL) {
			$this->userAuthentication->user['admin'] = $originalRole;
		}
	}

	/**
	 * Outputs specified text to the console window
	 * You can specify arguments that will be passed to the text via sprintf
	 * @see http://www.php.net/sprintf
	 *
	 * @param string $text Text to output
	 * @param array $arguments Optional arguments to use for sprintf
	 * @return void
	 * @api
	 */
	protected function output($text, array $arguments = array()) {
		$this->output->output($text, $arguments);
	}

	/**
	 * Outputs specified text to the console window and appends a line break
	 *
	 * @param string $text Text to output
	 * @param array $arguments Optional arguments to use for sprintf
	 * @return void
	 * @see output()
	 * @see outputLines()
	 * @api
	 */
	protected function outputLine($text = '', array $arguments = array()) {
		$this->output->outputLine($text, $arguments);
	}

	/**
	 * Formats the given text to fit into $this->output->getMaximumLineLength() and outputs it to the
	 * console window
	 *
	 * @param string $text Text to output
	 * @param array $arguments Optional arguments to use for sprintf
	 * @param integer $leftPadding The number of spaces to use for indentation
	 * @return void
	 * @see outputLine()
	 * @api
	 */
	protected function outputFormatted($text = '', array $arguments = array(), $leftPadding = 0) {
		$this->output->outputFormatted($text, $arguments, $leftPadding);
	}

	/**
	 * Exits the CLI through the dispatcher and makes sure that the framework is properly shut down.
	 *
	 * If your command relies on functionality which is triggered through the Bootstrap
	 * shutdown (such as the persistence framework), you must use quit() instead of exit().
	 *
	 * @param integer $exitCode Exit code to return on exit (see http://www.php.net/exit)
	 * @throws StopActionException
	 * @return void
	 */
	protected function quit($exitCode = 0) {
		$this->response->setExitCode($exitCode);
		throw new StopActionException;
	}

	/**
	 * Sends the response and exits the CLI without any further code execution
	 * Should be used for commands that flush code caches.
	 *
	 * @param integer $exitCode Exit code to return on exit
	 * @return void
	 */
	protected function sendAndExit($exitCode = 0) {
		$this->response->send();
		exit($exitCode);
	}


	/**
	 * Creates a logger which outputs to the console
	 *
	 * @param int $minimumLevel Minimum log level that should trigger output
	 * @param array $options Additional options for the console writer
	 * @return LoggerInterface
	 */
	protected function createDefaultLogger($minimumLevel = LogLevel::DEBUG, $options = array()) {
		$options['output'] = new SymfonyConsoleOutput();
		$logger = new Logger(get_class($this));
		$logger->addWriter($minimumLevel, new ConsoleWriter($options));

		return $logger;
	}

}
