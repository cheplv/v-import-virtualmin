#VestaCP import accounts from Virtualmin (webmin)

##Usage (with root permissions)

###Clone repository
git clone https://github.com/cheplv/v-import-virtualmin.git /usr/local/v-import-virtualmin

###Create symlink
ln -s /usr/local/v-import-virtualmin/v-import-virtualmin.php /usr/local/vesta/bin/v-import-virtualmin

###Remote SSH Passwordless Auth
Add public root user ssh key (/root/.ssh/id_rsa.pub) to remote authorized_keys.

###Run import  
v-import-webmin ssh://root@remote-virtualmin.server.com "optional-remote-username"

##Dependencies:
imapcopy: import mail accounts from remote server. http://home.arcor.de/armin.diehl/imapcopy/imapcopy.html
