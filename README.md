# CS340 Slack bot

To deploy, ssh into one of the CS department lab machines; cd into `/users/groups/cs340ta/slack`; and `git pull` the main branch.

To update the list of TAs, simply change `secrets/ta_slack_user_ids.json` in the same location.

## TODO

It is currently a PHP server, but that is being phased out in favor of Python. So far, all logic and functionality has been moved into `slackbot.py`. The server entrypoint is still `index.php`, but all it does is pass the HTTP request data into `python3 slackbot.py`.
