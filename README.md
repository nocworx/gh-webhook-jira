# gh-webhook-jira

## Required Environment

- `DEBUG` Set to true to enable debugging (optional)
- `GITHUB_API_TOKEN` Personal Access Token for user
- `SECRET` Secret setup in webhook config
- `JIRA_URL` URL to Jira (excluding trailing /)
- `JIRA_USERNAME` Username of Jira user
- `JIRA_PASSWORD` Password of Jira user
- `JIRA_ISSUE_PREFIX` Prefix for Jira issues (i.e. `NSD`)

## Project Webhook Setup

- Payload URL: Url to where this project is hosted
- Content type: application/json
- Secret: Same that is configured for the environment above
- Let me select individual events:
  - Pull request
- Active
