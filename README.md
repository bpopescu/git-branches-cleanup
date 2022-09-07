# git-branches-cleanup
A tool to clean git repository

To start, you will need to create an ini file.

By default the script is looking for GitBranches.ini next to the php, but you can place the ini anywhere and give the path as parameter to the script.

Ini example:
```
users[] = username1
users[] = username2
statuses[] = Released
statuses[] = Closed
project = PROJECT-KEY
weeks = 52
token = 'OTQ1NTIyNzk3ODI3OmozU1bUwqlR29IZk6FtiCs9rPB1'
host = 'https://jiradomain.com'
git_folder = /path/to/git/folder