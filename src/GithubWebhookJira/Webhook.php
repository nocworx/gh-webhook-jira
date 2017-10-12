<?php

namespace GithubWebhookJira;

use \Symfony\Component\HttpFoundation\Request;
use \Silex\Application;
use \Github\Client as Client;
use \JiraRestApi\Configuration\ArrayConfiguration;
use \JiraRestApi\Issue\IssueService;
use \JiraRestApi\Issue\Comment;
use \JiraRestApi\Issue\Transition;

class Webhook {

  /** @var string 'opened' Action */
  const ACTION_OPENED = 'opened';

  /** @var string 'reopened' Action */
  const ACTION_REOPENED = 'reopened';

  /** @var string 'edited' Action */
  const ACTION_EDITED = 'edited';

  /** @var string 'closed' Action */
  const ACTION_CLOSED = 'closed';

  /** @var int In Progress -> Peer Review Transition */
  const TRANSITION_PEER_REVIEW = 61;

  /** @var int Peer Review -> Done Transition */
  const TRANSITION_DONE = 91;

  /** @var int Peer Review -> Review Rejected Transition */
  const TRANSITION_REVIEW_REJECTED = 121;

  /** @var string Done Resolution */
  const RESOLUTION_DONE = 'Done';

  /**
   * Our Silex application
   * @var \Silex\Application
   */
  private $_app;

  /**
   * Our Request object
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $_request;

  /**
   * Our github client
   * @var \Github\Client
   */
  private $_github;

  /**
   * Jira cloud issue API service
   * @var \JiraRestApi\Issue\IssueService
   */
  private $_issue;

  /**
   * The prefix for Jira issues
   * @var string
   */
  private $_issue_prefix;

  /**
   * Github Webhook Secret
   * @var string
   */
  private $_secret = '';

  /**
   * URL for Jira
   * @var string
   */
  private $_jira_url;

  /**
   * Raw data from hook request
   * @var string
   */
  private $_raw_data = '';

  /**
   * Construct this object
   *
   * @param \Silex\Application $app
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  public function __construct(Application $app, Request $request) {
    $this->_app = $app;
    $this->_request = $request;

    $this->_secret = getenv('SECRET');
    $this->_issue_prefix = getenv('JIRA_ISSUE_PREFIX');
    $this->_jira_url = getenv('JIRA_URL');

    // Setup Github API
    $this->_github = new Client();
    $this->_github->authenticate(
      getenv('GITHUB_API_TOKEN'),
      Client::AUTH_HTTP_TOKEN
    );

    // Setup Jira API
    $config = new ArrayConfiguration([
      'jiraHost' => $this->_jira_url,
      'jiraUser' => getenv('JIRA_USERNAME'),
      'jiraPassword' => getenv('JIRA_PASSWORD'),
      'jiraLogLevel' => 'EMERGENCY',
    ]);
    $this->_issue = new IssueService($config);


    $this->_raw_data = $request->getContent();
  }

  /**
   * Check if the webhook is a valid request
   *
   * @return boolean
   */
  public function isValid() : bool {
    $event = $this->_request->headers->get('X-Github-Event');
    $signature = $this->_request->headers->get('X-Hub-Signature');
    list($algo, $sig) = explode('=', $signature);
    $hash = hash_hmac($algo, $this->_raw_data, $this->_secret);
    return $hash === $sig;
  }

  /**
   * Get our data
   *
   * @return \stdClass
   */
  private function _getData() : \stdClass {
    return json_decode($this->_raw_data);
  }

  /**
   * Process our webhook data
   *
   * @return void
   */
  public function process() {
    switch ($this->_getData()->action) {
      case self::ACTION_REOPENED:
        /* falls through */
      case self::ACTION_EDITED:
        /* falls through */
      case self::ACTION_OPENED:
        $this->_processPullRequestOpen();
        break;
      case self::ACTION_CLOSED:
        $this->_processPullRequestClose();
        break;
      default:
        // do nothing
    }
  }

  /**
   * Handle opened PR action
   */
  private function _processPullRequestOpen() {
    $items = $this->_getJiraItems();
    $check = array_filter($items, function($item) {
      return empty($item['url']);
    });

    if ( !empty($check)) {
      $this->_updateUrls($check);
    }

    foreach ($items as $item) {
      $transition = new Transition();
      $transition->setTransitionId(self::TRANSITION_PEER_REVIEW);
      $this->_issue->transition($item['key'], $transition);
      $comment = new Comment();
      $url = $this->_getData()->pull_request->html_url;
      $repo_name = $this->_getData()->repository->full_name;
      $number = $this->_getData()->pull_request->number;
      $msg = "PR Opened: [{$repo_name}#{$number}|$url]";
      $comment->setBody($msg);
      $this->_issue->addComment($item['key'], $comment);
    }
  }

  /**
   * Handle closed PR action
   */
  private function _processPullRequestClose() {
    if ($this->_getData()->pull_request->merged === true) {
      foreach ($this->_getJiraItems() as $item) {
        $transition = new Transition();
        $transition->fields['resolution'] = ['name' => self::RESOLUTION_DONE];
        $transition->setTransitionId(self::TRANSITION_DONE);
        $this->_issue->transition($item['key'], $transition);
      }
    } else {
      foreach ($this->_getJiraItems() as $item) {
        $transition = new Transition();
        $transition->setTransitionId(self::TRANSITION_REVIEW_REJECTED);
        $this->_issue->transition($item['key'], $transition);
      }
    }
  }

  /**
   * Update the Jira IDs with URLs in the pull request
   */
  private function _updateUrls() {
    $body = $this->_getData()->pull_request->body;
    $user = $this->_getData()->repository->owner->login;
    $repo = $this->_getData()->repository->name;
    $number = $this->_getData()->pull_request->number;
    $regex =
      '((?:(close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved))' .
      '\s(?!' .
      $this->_jira_url .
      ')?(' .
      $this->_issue_prefix .
      '-[0-9]+))i';

    $replace = '\\1 [\\2](' . $this->_jira_url . '/browse/\\2)';
    $body = preg_replace($regex, $replace, $body);
    try {
      $this->_github->api('pull_request')->update($user, $repo, $number, [
        'body' => $body
      ]);
    } catch (\Throwable $e) {
      $this->_app['monolog']->debug($e->getMessage());
    }
  }

  /**
   * Get items from pull request body
   *
   * @return array
   */
  private function _getJiraItems() : array {
    $matches = [];
    preg_match_all(
      $this->_getIssueRegex(),
      $this->_getData()->pull_request->body,
      $matches,
      PREG_SET_ORDER
    );

    return array_map(function($item) {
      return [
        'key' => $item[2],
        'url' => $item[1]
      ];
    }, $matches);
  }

  /**
   * Gets the issue matching regex
   *
   * @return string
   */
  private function _getIssueRegex() : string {
    return
      '((?:close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved)' .
      '\s(?:(' .
      $this->_jira_url . '/browse/))?(' .
      $this->_issue_prefix .
      '-[0-9]+))i';
  }

}
