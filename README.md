# gh-webhook-jira

## Required Environment

- `DEBUG` Set to true to enable debugging (optional)
- `GITHUB_API_TOKEN` Personal Access Token for user
- `SECRET` Secret setup in webhook config
- `JIRA_URL` URL to Jira (excluding trailing /)
- `JIRA_USERNAME` Username of Jira user
- `JIRA_PASSWORD` Password of Jira user
- `JIRA_ISSUE_PREFIX` Prefix for Jira issues (i.e. `NSD`)
- `JIRA_TRANSITION_OPENED` Transition ID for Opened PRs
- `JIRA_TRANSITION_OPENED_EXTRA` JSON String containing extra fields for opened PR transition
- `JIRA_TRANSITION_CLOSED` Transition ID for Closed PRs
- `JIRA_TRANSITION_CLOSED_EXTRA` JSON String containing extra fields for closed PR transition
- `JIRA_TRANSITION_MERGED` Transition ID for Merged PRs
- `JIRA_TRANSITION_MERGED_EXTRA` JSON String containing extra fields for merged PR transition

## Project Webhook Setup

- Payload URL: Url to where this project is hosted
- Content type: application/json
- Secret: Same that is configured for the environment above
- Let me select individual events:
  - Pull request
- Active
