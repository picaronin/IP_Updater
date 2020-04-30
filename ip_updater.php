<?php
/*
Copyright (c) 2015-2018, Hj Ahmad Rasyid Hj Ismail "ahrasis" ahrasis@gmail.com
Project IP Updater for debian and ubuntu, ispconfig 3 and dynamic ipv4 ip users.
BSD3 License. All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

*	Redistributions of source code must retain the above copyright notice,
	this list of conditions and the following disclaimer.
*	Redistributions in binary form must reproduce the above copyright notice,
	this list of conditions and the following disclaimer in the documentation
	and/or other materials provided with the distribution.
*	Neither the name of ISPConfig nor the names of its contributors
	may be used to endorse or promote products derived from this software without
	specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 'AS IS' AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

/*	Check internet access by using accessing www.google.com. Log its
	error then restart ip updater if its connection failed. */

$sock = fsockopen('www.google.com', 80, $errno, $errstr, 10);
if (!$sock) {
    printf("\r\n¡Sin conexión a internet! Vuelva a intentar actualizar IP. \r\n, $errstr ($errno)\r\n");
    exit();
}

/*	Get database access by using ispconfig default configuration so no
	user and its password are disclosed. Exit if its connection failed */

require_once 'config.inc.php';
require_once 'app.inc.php';

$ip_updater = mysqli_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database']);
if (mysqli_connect_errno()) {
    printf("\r\n¡Conexión a la base de datos ISPConfig fallida! \r\n", mysqli_connect_error());
    exit();
}

/*	Else, it works. Now get public ip from a reliable source.
	We are using this but you can define your own. But We just
	need ipv4. So we exit if its filtering failed */

$public_ip = file_get_contents('http://dynamicdns.park-your-domain.com/getip');
if(!filter_var($public_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === true) {
	printf("\r\n¡IPV4 para el filtrado de IP pública falló! \r\nPuede que necesite usar otra fuente de IP. \r\n");
	exit();
}

/*	Else, it's truly ipv4. Now we obtain the server ip,
	based on server id. Do change your server id accordingly. */

$query_ip = mysqli_query($ip_updater, 'SELECT ip_address FROM server_ip WHERE server_id =1');
list($db_ip) = mysqli_fetch_row($query_ip);

/*	Other than the above ip, we also need soa ip from bind files */

$binds = glob('/etc/bind/pri.*');
foreach ($binds as $bind)
	$filename[] = $bind;
$matcher = file_get_contents($filename[0]);
preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $matcher, $matched);
$soa_ip = $matched[0];

/*	If the server public ip matches both, exit. Log is
	for error only, so we close database connection and restart
	apache without any logging for now on. However, you may
	enable it, by uncommenting the log line below this. */

if(!$db_ip || !$public_ip || !$soa_ip) {
	printf("\r\nERROR: Se aborta. No se localiza la IP pública actual o no esta definida la IP del servidor. \r\n");
	exit();
}

if(($public_ip == $db_ip) && ($public_ip == $soa_ip)) {
	// printf("\r\nAviso del servidor: Los archivos de zona de SOA y las direcciones IP públicas coinciden. \r\n");
	exit();
}

/* If not, start to change soa zone files with the new ip */

if($public_ip != $soa_ip) {
	foreach ($binds as $bind) {
		$file = file_get_contents($bind);
		file_put_contents($bind, preg_replace("/$soa_ip/", "$public_ip", $file));
	}

	// Warn and update if soa zone files update failed.
	foreach(file($bind) as $binding=>$b) {
		if(strpos($b, "$soa_ip")==true) {
			printf("\r\n¡Las actualizaciones de archivos de zona SOA fallaron! \r\nEl código de actualización de archivos de zona puede necesitar una reparación o actualización. \r\n");
			exit();
		}
	}
}

/* Then, we update our database with the new ip */

if($public_ip != $db_ip) {
	$update1 = mysqli_query($ip_updater, "UPDATE dns_rr SET data = replace(data, '$db_ip', '$public_ip')");
	$update2 = mysqli_query($ip_updater, "UPDATE server_ip SET ip_address = replace(ip_address, '$db_ip', '$public_ip')");
	$update3 = mysqli_query($ip_updater, "UPDATE web_domain SET ip_address = replace(ip_address, '$db_ip', '$public_ip')");
	$update4 = mysqli_query($ip_updater, "UPDATE server SET config = replace(config, '$db_ip', '$public_ip')");

	list($db_uno, $db_dos, $db_tres) = explode(".", $db_ip);
	list($public_uno, $public_dos, $public_tres) = explode(".", $public_ip);
	$db_plantilla = $db_uno . '.' . $db_dos . '.' . $db_tres;
	$public_plantilla = $public_uno . '.' . $public_dos . '.' . $public_tres;

	$update5 = mysqli_query($ip_updater, "UPDATE server SET config = replace(config, '$db_plantilla', '$public_plantilla')");

	// Warn and exit if database update failed.
	$query_new_ip = mysqli_query($ip_updater, 'SELECT ip_address FROM server_ip WHERE server_id =1');
	list($db_new_ip) = mysqli_fetch_row($query_new_ip);
	if ($public_ip != $db_new_ip) {
		printf("\r\n¡Actualización de la base de datos fallida! \r\nEl código de actualización de la base de datos puede necesitar una corrección o actualización. \r\n");
		exit();
	}
}

/*	Now do dns resync so that above changes updated properly. */

$zones = $app->db->queryAllRecords("SELECT id,origin,serial FROM dns_soa WHERE active = 'Y'");
if(is_array($zones) && !empty($zones)) {
	foreach($zones as $zone) {
		$records = $app->db->queryAllRecords("SELECT id,serial FROM dns_rr WHERE zone = ".$zone['id']." AND active = 'Y'");
		if(is_array($records)) {
			foreach($records as $rec) {
				$new_serial = $app->validate_dns->increase_serial($rec["serial"]);
				$app->db->datalogUpdate('dns_rr', "serial = '".$new_serial."'", 'id', $rec['id']);
			}
		}
		$new_serial = $app->validate_dns->increase_serial($zone["serial"]);
		$app->db->datalogUpdate('dns_soa', "serial = '".$new_serial."'", 'id', $zone['id']);
	}
}

/*	Lastly, congratulations! All updates are successful. Log is
	for error only, so we close database connection and restart
	apache without any logging for now on. However, you may
	enable it, by uncommenting the line below this. */

printf("\r\n¡Se han actualizado correctamente los archivos de zona SOA y la Base de Datos! \r\n");
printf("La nueva IP es: $public_ip \r\n");

mysqli_close($ip_updater);

/*	You should define your server software to restart if it is not here. */
exec('sudo -u root service apache2 restart');
exec('sudo -u root service bind9 force-reload');
exec('sudo -u root service bind9 restart');
printf("¡Se han reiniciado con Exito el Servidor Apache y el Servidor de DNS! \r\n");

/* Comment this out if you want to reboot afterwards */
// exec('sudo -u root reboot');
?>