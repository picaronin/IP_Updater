# IP_Updater for ISPConfig 3.1
Dynamic IP Automatic Updater for ISPConfig 3.1 Server.

## Install
cd `/usr/local/ispconfig/interface/lib`
wget `https://raw.githubusercontent.com/picaronin/IP_Updater/master/ip_updater.php`
cd `/etc/cron.d`
wget `https://raw.githubusercontent.com/picaronin/IP_Updater/master/ip_updater`
mkdir `/usr/share/ip_updater`
cd `/usr/share/ip_updater`
wget `https://raw.githubusercontent.com/picaronin/IP_Updater/master/ip_updater.sh`
chmod +x ip_updater.sh

## License
[GNU General Public License v2](http://opensource.org/licenses/GPL-2.0)