<?php
/**
 * MIT License
 *
 * Copyright (c) 2017 NocWorx
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */

namespace NocWorx\GithubWebhookJira;

use \Symfony\Component\HttpFoundation\Request;
use \Silex\Application;
use \Github\Client;
use \JiraRestApi\Configuration\ArrayConfiguration;
use \JiraRestApi\Issue\IssueService;
use \JiraRestApi\Issue\Comment;
use \JiraRestApi\Issue\Transition;

/**
 * Github Webhook class
 */
class Webhook {

  /** @var string 'opened' Action */
  const ACTION_OPENED = 'opened';

  /** @var string 'reopened' Action */
  const ACTION_REOPENED = 'reopened';

  /** @var string 'edited' Action */
  const ACTION_EDITED = 'edited';

  /** @var string 'merged' meta action */
  const ACTION_MERGED = 'merged';

  /** @var string 'closed' Action */
  const ACTION_CLOSED = 'closed';

  /** @var string 'open' pull request */
  const PR_STATE_OPEN = 'open';

  /** @var string 'closed' pull request */
  const PR_STATE_CLOSED = 'closed';

  /** @var \Silex\Application  Our Silex application */
  private $_app;

  /** @var \Symfony\Component\HttpFoundation\Request Our Request object */
  private $_request;

  /** @var \Github\Client Our github client */
  private $_github;

  /** @var \JiraRestApi\Issue\IssueService Jira cloud issue API service */
  private $_issue;

  /** @var string Raw data from hook request */
  private $_raw_data = '';

  /** @var array Configuration array */
  private $_config = [];

  /**
   * Construct this object
   *
   * @param \Silex\Application $app
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param array $config
   */
  public function __construct(
    Application $app,
    Request $request,
    array $config
  ) {
    $this->_app = $app;
    $this->_request = $request;
    $this->_config = $config;

    // Setup Github API
    $this->_github = new Client();
    $this->_github->authenticate(
      $this->_config['api_token'],
      Client::AUTH_HTTP_TOKEN
    );

    // Setup Jira API
    $config = new ArrayConfiguration([
      'jiraHost' => $this->_config['jira_url'],
      'jiraUser' => $this->_config['jira_username'],
      'jiraPassword' => $this->_config['jira_password'],
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
    list($algo, $sig) = explode(
      '=',
      $this->_request->headers->get('X-Hub-Signature')
    );
    return hash_hmac(
      $algo,
      $this->_raw_data,
      $this->_config['secret']
    ) === $sig;
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
    if (! isset($this->_getData()->pull_request)) {
      return;
    }

    switch ($this->_getData()->action) {
      case self::ACTION_EDITED:
        $this->_processPullRequestEdit();
        break;
      case self::ACTION_REOPENED:
        // falls through
      case self::ACTION_OPENED:
        $this->_updatePullRequest();
        $this->_transitionIssues(self::ACTION_OPENED);
        break;
      case self::ACTION_CLOSED:
        $action = ($this->_getData()->pull_request->merged) ?
          self::ACTION_MERGED :
          self::ACTION_CLOSED;
        $this->_transitionIssues($action);
        break;
      default:
        // do nothing
    }
  }

  /**
   * Handle edited PR action
   */
  private function _processPullRequestEdit() {
    $this->_updatePullRequest();

    switch ($this->_getData()->pull_request->state) {
      case self::PR_STATE_OPEN:
        $this->_transitionIssues(self::ACTION_OPENED);
        break;
      case self::PR_STATE_CLOSED:
        $action = ($this->_getData()->pull_request->merged) ?
          self::ACTION_MERGED :
          self::ACTION_CLOSED;
        $this->_transitionIssues($action);
        break;
    }
  }

  /**
   * Perform a transition on jira issues
   *
   * @param string $action The action to perform the transition on
   */
  private function _transitionIssues(string $action) {
    $trans_id = $this->_config['transition'][$action]['id'];
    $fields = $this->_config['transition'][$action]['fields'];
    $this->_app['monolog']->debug('ACTION: ' . $action);
    foreach ($this->_getJiraLinks() as $item) {
      $this->_app['monolog']->debug($item);
      $transition = new Transition();
      $transition->setTransitionId($trans_id);
      if (! empty($fields)) {
        $transition->fields = $fields;
      }
      try {
        $this->_issue->transition($item['key'], $transition);
      } catch (\Throwable $e) {
        if ($this->_app['debug']) {
          $this->_app['monolog']->debug(
            'FAILED TO TRANSITION: ID:' .
            $trans_id .
            ' FIELDS: ' .
            var_export($fields, true)
          );
          $this->_app['monolog']->debug($e->getMessage());
        }
      }
    }
  }

  /**
   * Update the Pull request with Jira IDs as URLs and tagging in title
   */
  private function _updatePullRequest() {
    $regex =
      '((\s|^)(' . preg_quote($this->_config['issue_prefix']) . '-[0-9]+))i';

    $body = preg_replace(
      $regex,
      '\\1[\\2](' . $this->_config['jira_url'] . '/browse/\\2)',
      $this->_getData()->pull_request->body
    );

    $title = $this->_getData()->pull_request->title;

    $issue_keys = implode(
      '|',
      array_filter($this->_getJiraItems(), function($item) use ($title) {
        return strpos($title, $item) === false;
      })
    );

    if (! empty($issue_keys)) {
      $title .= " [{$issue_keys}]";
    }

    if (
      $body === $this->_getData()->pull_request->body &&
      $title === $this->_getData()->pull_request->title
    ) {
      return;
    }

    try {
      $this->_github->api('pull_request')->update(
        $this->_getData()->repository->owner->login,
        $this->_getData()->repository->name,
        $this->_getData()->pull_request->number,
        ['body' => $body, 'title' => $title]
      );
    } catch (\Throwable $e) {
      if ($this->_app['debug']) {
        $this->_app['monolog']->debug('FAILED TO UPDATE PR');
        $this->_app['monolog']->debug($e->getMessage());
      }
    }
  }

  /**
   * Get jira links from pull request body
   *
   * @return array
   */
  private function _getJiraLinks() : array {
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
   * Get a list of Jira IDs within pull request body
   *
   * @return array
   */
  private function _getJiraItems() : array {
    $matches = [];
    preg_match_all(
      '((' . preg_quote($this->_config['issue_prefix']) . '-[0-9]+))i',
      $this->_getData()->pull_request->body,
      $matches
    );

    return array_unique($matches[0], SORT_STRING);
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
      preg_quote($this->_config['jira_url']) .
      '/browse/))?(' .
      preg_quote($this->_config['issue_prefix']) .
      '-[0-9]+))i';
  }

}
