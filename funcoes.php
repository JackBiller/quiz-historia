<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Conexao
{
	private static $conexao = null;
	public $db_driver;

	function __construct($config)
	{
		$db_host 			= $config->db_host;
		$db_nome 			= $config->db_nome;
		$db_usuario 		= $config->db_usuario;
		$db_senha 			= $config->db_senha;
		$this->db_driver 	= $config->db_driver;

		if ($this->db_driver == 'firebird') {
			$dt_encode = $config->dt_encode;

			eval("
			self::\$conexao = ibase_connect(
				\$db_host . '\\' . \$db_nome
				, \$db_usuario
				, \$db_senha
				, \$dt_encode
			) or die(\"Ibase: Não foi possível conectar-se ao servidor [\$db_host/\$db_nome].\");");
		}
		if ($this->db_driver == 'mysql') {
			try {
				# Atribui o objeto PDO à variável $conexao.
				self::$conexao = new PDO(
					$this->db_driver . ":host=$db_host; dbname=$db_nome",
					$db_usuario,
					$db_senha,
					array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
				);
				# Garante que o PDO lance exceções durante erros.
				self::$conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			} catch (PDOException $e) {
				# Então não carrega nada mais da página.
				echo 'ERROR: ' . $e->getMessage();
			}
		}
		if ($this->db_driver == 'sqlsrv') {
			$db_instancia 	= $config->db_instancia;
			$dt_port 		= $config->db_port;
			try {
				self::$conexao = new PDO(
					$this->db_driver . ":Server={$db_host}\\{$db_instancia},{$dt_port};Database={$db_nome}",
					$db_usuario,
					$db_senha,
					array(PDO::SQLSRV_ENCODING_UTF8 => 1)
				);
			} catch (PDOException $e) {
				// echo "Drivers disponiveis: " . implode( ",", PDO::getAvailableDrivers() );
				echo 'ERROR: ' . $e->getMessage();
			}
		}
	}
	private function __clone() {}

	public function __wakeup() {}

	public static function Connect()
	{
		if (!isset(self::$conexao)) {
			new Conexao($this->path);
			// eval("new Conexao(\$this->path);");
		}
		return self::$conexao;
	}
}

#[\AllowDynamicProperties]
class PadraoObjeto
{
	var $debug = 'OK';
	public function get($nome_campo)
	{
		return $this->$nome_campo;
	}

	public function set($valor, $nome_campo)
	{
		$this->$nome_campo = $valor;
	}

	public function check($nome_campo)
	{
		return isset($this->$nome_campo);
	}

	public function push($valor, $nome_campo)
	{
		if (gettype($this->$nome_campo) == "array") array_push($this->$nome_campo, $valor);
	}

	public function removeQuebra($tipo, $valor)
	{
		$valor = 	str_replace(
			"\"",
			'\'',
			str_replace("\r", '', $valor)
		);
		if ($tipo == 'html') return 		str_replace(
			"\t",
			'&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
			str_replace("\n", '<br>', $valor)
		);
		else 				return 		str_replace(
			"\t",
			' ',
			str_replace("\n", '', $valor)
		);
	}

	public function setOptions($option = array())
	{
		foreach ($option as $key => $value) {
			$this->$key = $value;
		}
	}
}

#[\AllowDynamicProperties]
class FalseDebug extends PadraoObjeto
{
	public function __construct($msm)
	{
		if (!empty($msm) && gettype($msm) == 'string') $this->set($msm, 'debug');
	}
}

#[\AllowDynamicProperties]
class MessageResponse
{
	public function __construct($msm, $varName = 'debug')
	{
		if (!empty($msm) && gettype($msm) == 'string') $this->$varName = $msm;
	}
}

function defaultMsmError($msm, $tag = 'error', $status = 400)
{
	// http_response_code($status);
	return json_encode(new MessageResponse($msm, $tag));
}

function defaultMsm($msm, $tag = 'message')
{
	return json_encode(new MessageResponse($msm, $tag));
}

#[\AllowDynamicProperties]
class Generico extends PadraoObjeto
{
	var $BR;
	var $variable = array('debug');
	var $debug = "OK";
	var $firebird = false;

	public function __construct($row, $firebird = false)
	{
		$this->firebird = $firebird;
		$contIten = 0;
		while (current($row) !== false) {
			if ($contIten % 2 == 0 || $this->get('firebird')) {
				$keys = key($row);
				$valor = $this->get('firebird') ? $row->$keys : $row[$keys];
				// if (!in_array($keys, $variable)) array_push($variable, $keys);
				array_push($this->variable, $keys);
				$this->$keys = gettype($valor) == 'string' ? $this->removeQuebra('html', $valor) : $valor;
			}
			next($row);
			$contIten++;
		}
	}
}

function getConection($config = null)
{
	if ($config == null)
		$config = json_decode(CONFIG_ENV);

	$conn = new Conexao($config);
	return $conn->Connect();
}

function getMsmDebug($msm, $toJson = false)
{
	return $toJson ? toJson(new FalseDebug($msm)) : new FalseDebug($msm);
}

function getQuery($pdo, $sql)
{
	if (gettype($sql) == 'object') {
		$driveDB = gettype($pdo) == 'resource' ? 'Firebird' : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
		$driveDB = str_replace('sqlsrv', 'SQLServer', $driveDB);
		$driveDB = str_replace('mysql', 'MySQL', $driveDB);

		$typeQuery = substr(get_class($sql), 1);
		eval('$sql = new Q' . $driveDB . $typeQuery . '($sql);');
		$sql = $sql->returnSQL();
	}
	return $sql;
}

function padraoResultado($pdo, $sql, $msm = 'Nenhum resultado encontrado!')
{
	$sql = getQuery($pdo, $sql);
	$arrayResultado = array();

	if (gettype($pdo) == 'resource') {
		eval("
		\$resultado = ibase_query(\$pdo, \$sql);
		\$row = ibase_fetch_object(\$resultado);
		if (empty(\$row)) 	array_push(\$arrayResultado, new FalseDebug(\$msm));
		else 			do 	array_push(\$arrayResultado, new Generico(\$row, true)); while(\$row = ibase_fetch_object(\$resultado));");
	} else {
		$verifica = $pdo->query($sql);
		foreach ($verifica as $dados) {
			array_push($arrayResultado, new Generico($dados));
		}
		if (sizeof($arrayResultado) == 0) array_push($arrayResultado, new FalseDebug($msm));
	}
	return $arrayResultado;
}

function padraoExecute($pdo, $sql, $table = '')
{
	$sql = getQuery($pdo, $sql);

	if (gettype($pdo) == 'resource') {
		eval("
		\$resultado = ibase_query(\$pdo, \$sql);
		COMMIT_WORK(\$pdo);
		if (\$table) {
			\$resultado = ibase_fetch_row(\$resultado);
			\$resultado = \$resultado['0'];
		};");
	} else {
		$stmt = $pdo->prepare($sql);
		$resultado = $stmt->execute();
		if ($table != '' && $resultado == 1) {
			// $resultado = "SELECT ID_$table FROM $table ORDER BY ID_$table DESC LIMIT 1";
			if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlsrv') {
				// $resultado = "SELECT SCOPE_IDENTITY() AS ID";
				$resultado = "SELECT @@IDENTITY AS ID";
			} else {
				$resultado = "SELECT LAST_INSERT_ID();";
			}
			$verifica = $pdo->query($resultado);
			foreach ($verifica as $dados) $resultado = $dados[0];
		}
	}
	return $resultado;
}

function COMMIT_WORK($pdo)
{
	eval("\$resultado = ibase_query(\$pdo, \"COMMIT WORK;\");");
}

function printQuery($sql, $isHtml = false, $boolComentario = false)
{
	$sql = explode("\n", str_replace("\r", '', str_replace("\t", "    ", $sql)));

	for ($i = 0; $i < sizeof($sql); $i++) {
		// remover comentario
		if (!$boolComentario) {
			if (strpos($sql[$i], "--") != '') {
				$sql[$i] = explode('--', $sql[$i]);
				$sql[$i] = $sql[$i][0];
			}
		}
		// remover espaço no final da linha
		while (substr($sql[$i], strlen($sql[$i]) - 1, 1) == ' ') {
			$sql[$i] = substr($sql[$i], 0, strlen($sql[$i]) - 1);
		}
		// remover linhas vazias
		if ($sql[$i] == '') {
			array_splice($sql, $i, 1);
			$i--;
		}
	}
	// calcular identação do codigo
	$space = -1;
	for ($i = 1; $i < sizeof($sql); $i++) {
		if ($sql[$i] != '') {
			$bls = explode(' ', $sql[$i]);
			$spaceTemp = 0;
			for ($j = 0; $j < sizeof($bls); $j++) {
				if ($bls[$j] == '') 	$spaceTemp++;
				else 					$j = sizeof($bls);
			}
			if ($space == -1 || $space > $spaceTemp) $space = $spaceTemp;
		}
	}
	// retirnar a identação do codigo
	for ($i = 1; $i < sizeof($sql); $i++) {
		$sql[$i] = substr($sql[$i], $space, strlen($sql[$i]));
	}
	$sql = implode("\n", $sql);

	// formata para HTML
	if ($isHtml) {
		$sql =
			str_replace(
				"    ",
				'&nbsp;&nbsp;&nbsp;&nbsp;',
				str_replace(
					"\n",
					"<br>",
					$sql
				)
			);
	}
	echo $sql;
}

function in_comando($comando, $usuario = '')
{
	if ($usuario == '') {
		global $usuario_Global;
		$usuario = $usuario_Global;
	}
	return in_array($comando, explode(',', $usuario->get('COMANDO')));
}

function tratarParam($text)
{
	$text = str_replace("'", "\\'", str_replace("\"", "\\\"", $text));
	return $text;
}

function letraMaiuscula($letra)
{
	switch ($letra) {
		case 'a':
			$letra = 'A';
			break;
		case 'b':
			$letra = 'B';
			break;
		case 'c':
			$letra = 'C';
			break;
		case 'd':
			$letra = 'D';
			break;
		case 'e':
			$letra = 'E';
			break;
		case 'f':
			$letra = 'F';
			break;
		case 'g':
			$letra = 'G';
			break;
		case 'h':
			$letra = 'H';
			break;
		case 'i':
			$letra = 'I';
			break;
		case 'j':
			$letra = 'J';
			break;
		case 'k':
			$letra = 'K';
			break;
		case 'l':
			$letra = 'L';
			break;
		case 'm':
			$letra = 'M';
			break;
		case 'n':
			$letra = 'N';
			break;
		case 'o':
			$letra = 'O';
			break;
		case 'p':
			$letra = 'P';
			break;
		case 'q':
			$letra = 'Q';
			break;
		case 'r':
			$letra = 'R';
			break;
		case 's':
			$letra = 'S';
			break;
		case 't':
			$letra = 'T';
			break;
		case 'u':
			$letra = 'U';
			break;
		case 'v':
			$letra = 'V';
			break;
		case 'w':
			$letra = 'W';
			break;
		case 'x':
			$letra = 'X';
			break;
		case 'y':
			$letra = 'Y';
			break;
		case 'z':
			$letra = 'Z';
			break;

		default:
			$letra = $letra;
			break;
	}
	return $letra;
}

function formatarNomeHeadTable($nome)
{
	$nome = str_replace("-", "_-_", $nome);
	$nomeVetor = explode("_", $nome);
	for ($i = 0; $i < (count($nomeVetor) - 1); $i++) {
		$nome = ($i == 0 ? '' : $nome . " ") . corretor(
			''
				. letraMaiuscula(substr($nomeVetor[$i], 0, 1))
				. substr($nomeVetor[$i], 1, strlen($nomeVetor[$i]))
		);
	}
	return $nome;
}

function formatarNomeHeadTable2($nome)
{
	$nome = str_replace("-", "_-_", $nome);
	$nomeVetor = explode("_", $nome);
	for ($i = 0; $i < sizeof($nomeVetor); $i++) {
		$nome = ($i == 0 ? '' : $nome . " ") . corretor(
			''
				. letraMaiuscula(substr($nomeVetor[$i], 0, 1))
				. substr($nomeVetor[$i], 1, strlen($nomeVetor[$i]))
		);
	}
	return $nome;
}

function formatarNomeCampo($nomeCampo, $qtdTirarUltimo)
{
	$arrayNomeCampo = explode("_", $nomeCampo);
	$nomeCampo = "";
	if (sizeof($arrayNomeCampo) - $qtdTirarUltimo > 0) {
		for ($i = 0; $i < sizeof($arrayNomeCampo) - $qtdTirarUltimo; $i++) {
			$nomeCampo .= $i == 0 ? $arrayNomeCampo[$i] : "_" . $arrayNomeCampo[$i];
		}
	}
	return $nomeCampo;
}

function juntaTodosMenosPrimeiro($array)
{
	$resultado = "";
	$cont = 0;
	for ($i = 1; $i < sizeof($array); $i++) {
		$resultado .= $cont == 0 ? $array[$i] : " " . $array[$i];
		$cont++;
	}
	return $resultado;
}

function retornaUltimaPosicao($array)
{
	$resultado = "";
	for ($i = sizeof($array) - 1; $i >= 0; $i--) {
		$resultado = $array[$i];
		$i = -1;
	}
	return $resultado;
}

function corretor($palavra)
{
	$preDirectory = "";
	$tentativas = 5;
	for ($i = 0; $i < $tentativas; $i++) {
		if (is_dir($preDirectory . "Dicionario")) : $preDirectory .= "Dicionario";
			$i = $tentativas;
		endif;
		$preDirectory .= "../";
	}
	include $preDirectory . "/dicionario.php";

	return $palavra;
}

/**********************************************************************************************/
/* FUNÇÕES PARA EMAIL */
/**********************************************************************************************/
class Email extends PadraoObjeto
{
	var $host;
	var $username;
	var $password;
	var $isGmail;
	var $emailFrom;
	var $nameFrom;
	var $emailAddress;
	var $nameAddress;
	var $subject = '';
	var $body = '';
	var $altBody = '';
	var $imgs = array();
	var $debug = false;

	function __construct($options = array())
	{
		$this->setOptions($options);
	}
}

function getEmailObj($configEnv)
{
	$mail = new Email();
	$mail->host 		= $configEnv->password_reset__host;
	$mail->username 	= isset($configEnv->password_reset__user)
		? $configEnv->password_reset__user
		: $configEnv->password_reset__email;
	$mail->emailFrom 	= $configEnv->password_reset__email;
	$mail->password 	= $configEnv->password_reset__psw;
	$mail->isGmail 		= $configEnv->password_reset__isGmail;
	$mail->nameFrom 	= $configEnv->config_email->name;
	return $mail;
}

function notificationEmail($title, $msm, $ck_dev = false)
{
	$configEnv = json_decode(CONFIG_ENV);
	$mail = getEmailObj($configEnv);
	$emails = $ck_dev ? $configEnv->emails_dev : $configEnv->emails_gerencia;
	for ($i = 0; $i < sizeof($emails); $i++) {
		$emailGerencia = $emails[$i];
		$mail->emailAddress = $emailGerencia->email;
		$mail->nameAddress 	= $emailGerencia->nome;
		$mail->subject 		= $title;
		$mail->body 		= $msm;
		enviarEmail($mail);
	}
}

function setParamBody($body, $params = array())
{
	foreach ($params as $key => $value) {
		$body = str_replace($key, $value, $body);
	}
	return $body;
}

function enviarEmail($email)
{
	require_once __DIR__ . '/../vendor/autoload.php';
	$mail = new PHPMailer();
	try {
		// Define os dados do servidor e tipo de conexão
		if ($email->debug) $mail->SMTPDebug = 1; 						// Habilita o debug na hora de enviar o email
		$mail->IsSMTP(); 												// Define que a mensagem será SMTP
		$mail->SMTPAuth 	= true; 									// Usa autenticação SMTP?
		// (obrigatório para alguns servidores, como o gmail)
		$mail->Port 		= $email->isGmail ? 465 : 587;
		$mail->Host 		= $email->host;
		$mail->Username 	= $email->username; 						// Usuário do servidor SMTP
		$mail->Password 	= $email->password; 						// Senha do servidor SMTP
		if ($email->isGmail) $mail->SMTPSecure 	= "ssl";
		$mail->SetFrom($email->emailFrom, $email->nameFrom);
		$mail->AddAddress($email->emailAddress, $email->nameAddress); 	// Define o remetente
		// Configurações do corpo do e-mail
		$mail->IsHTML(true); 											// Define que o e-mail será enviado como HTML
		$mail->CharSet 		= 'UTF-8'; 									// Charset da mensagem (opcional)
		$mail->Subject 		= $email->subject;
		$mail->Body 		= $email->body;

		foreach ($email->imgs as $img) {
			foreach ($img as $key => $value) {
				$mail->AddEmbeddedImage($value, $key);
			}
		}
		return $mail->Send() ? '1' : '0';
	} catch (Exception $e) {
		return '0';
	}
}

function getSocialNetworkToEmail($networks)
{
	$body = '';
	if (sizeof($networks) > 0) {
		$body .= '
			<p style="font-size:14px;text-align:center">
				Siga nas nossas redes sociais
			</p>
		';
	}
	$body .= '
		<table>
			<tbody>
				<tr>
	';
	foreach ($networks as $key => $value) {
		$link = '';
		switch ($key) {
			case 'instagram':
				$link = "https://www.instagram.com/$value";
				break;
			case 'facebook':
				$link = "https://www.facebook.com/$value";
				break;
		}
		$body .= '
					<td>
						<a href="' . $link . '" rel="noopener noreferrer" target="_blank">
							<img style="display:block; width:42px;height:42px;"
								src="cid:' . $key . '"
							/>
						</a>
					</td>
		';
	}
	$body .= '
				</tr>
			</tbody>
		</table>
	';
	return $body;
}

/**********************************************************************************************/
/* FUNÇÕES PARA ARQUIVOS E DIRETORIOS */
/**********************************************************************************************/
class Dir extends PadraoObjeto
{
	var $name;
	var $branchs = array();
	var $isFile = false;

	function __construct($name)
	{
		$this->name = $name;
	}
}

class File extends PadraoObjeto
{
	var $name;
	var $path;
	var $dateCriation;
	var $isFile = true;
	var $ext;
	var $height;
	var $width;

	function __construct($name, $path)
	{
		$this->name = $name;
		$this->path = $path;
	}
}

function createFile($name, $ctx)
{
	$myfile = fopen($name, "w") or die("Unable to open file!");
	fwrite($myfile, $ctx);
	fclose($myfile);
	return 1;
}

function ctxFile($file)
{
	if (!is_file($file)) return '';
	$myfile = fopen($file, "r") or die("Unable to open file!");
	$ctx = fread($myfile, filesize($file));
	fclose($myfile);
	return $ctx;
}

function copyFile($origin, $dist)
{
	$file = ctxFile($origin);
	return createFile($dist, $file);
}

function getObjFile($file, $path)
{
	$filObj = new File($file, $path . '/' . $file);
	$filObj->set(date('Y-m-d H:i:s', filemtime($path . '/' . $file)), 'dateCriation');
	return $filObj;
}

function listDir($path)
{
	$dir = new Dir($path);
	if (is_dir($path)) {
		$diretorio = dir($path);
		while ($file = $diretorio->read()) {
			if ($file != '.' && $file != '..') {
				if (is_dir($path . '/' . $file)) {
					$dir->push(listDir($path . '/' . $file), 'branchs');
				} else {
					$ext = explode('.', $file);
					array_splice($ext, 0, -1);
					$ext = implode('', $ext);

					$filObj = getObjFile($file, $path);

					$extsImgs = explode(',', 'PNG,JPG,TIFF,JPEG,BMP,PSD,EXIF,RAW,PDF,WEBP,GIF,EPS,SVG');
					if (in_array(strtoupper($ext), $extsImgs) && $size = getimagesize($path . '/' . $file)) {
						list($width, $height) = $size;
						$filObj->set($height, 'height');
						$filObj->set($width, 'width');
					}
					$filObj->set($ext, 'ext');
					$dir->push($filObj, 'branchs');
				}
			}
		}
	} else {
		$dir->set('Not Dir', 'name');
	}
	return $dir;
}

function copyDir($dirOrigin, $dirDist)
{
	if (is_dir($dirOrigin)) {
		if (!is_dir($dirDist)) mkdir($dirDist);

		$objects = scandir($dirOrigin);
		foreach ($objects as $object) {
			if ($object != '.' && $object != '..') {
				if (is_dir($dirOrigin . DIRECTORY_SEPARATOR . $object)) {
					mkdir($dirDist . DIRECTORY_SEPARATOR . $object);
					copyDir(
						$dirOrigin . DIRECTORY_SEPARATOR . $object,
						$dirDist . DIRECTORY_SEPARATOR . $object
					);
				} else if (is_file($dirOrigin . DIRECTORY_SEPARATOR . $object)) {
					copyFile(
						$dirOrigin . DIRECTORY_SEPARATOR . $object,
						$dirDist . DIRECTORY_SEPARATOR . $object
					);
				}
			}
		}
	}
}

function removeDir($dir)
{
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != '.' && $object != '..') {
				if (is_dir($dir . DIRECTORY_SEPARATOR . $object)) {
					removeDir($dir . DIRECTORY_SEPARATOR . $object);
				} else if (is_file($dir . DIRECTORY_SEPARATOR . $object)) {
					if (!unlink($dir . DIRECTORY_SEPARATOR . $object)) {
						// code in case the file was not removed
					}
					// wait a bit here?
				} else {
					// code for debug file permission issues
				}
			}
		}
		reset($objects);
		rmdir($dir);
	}
}

function deleteFile($file)
{
	if (!is_file($file)) return '';
	return unlink($file);
}

function setTextInFile($path, $text, $start, $end)
{
	$file = ctxFile($path);
	$file = explode($start, $file);
	$pre = $file[0];
	$file = explode($end, $file[1]);
	$pos = $file[1];
	$file = $pre . $start . $text . $end . $pos;
	deleteFile($path);
	createFile($path, $file);
}

function getTextInFile($path, $start, $end)
{
	$file = ctxFile($path);
	$file = explode($start, $file);
	$text = $file[1];
	$file = explode($end, $text);
	$text = $file[0];
	return $text;
}

function resolvPath($path, $createPath = true)
{
	$path = explode('/', $path);
	$pathNew = '';
	for ($i = 0; $i < sizeof($path); $i++) {
		$pathNew .= ($i == 0 ? '' : '/') . $path[$i];
		if (
			$path[$i] != '.' && $path[$i] != '..' && $path[$i] != ''
			&& !is_dir($pathNew) && $createPath
		) {
			mkdir($pathNew);
		}
	}
	return $pathNew . '/';
}

/**********************************************************************************************/
/* FUNÇÕES PARA OBJETOS JSON */
/**********************************************************************************************/
function toJson($variavel)
{
	$resultado = $variavel;
	if (gettype($variavel) == 'object') $resultado = objectEmJson($variavel);
	else if (gettype($variavel) == 'array') $resultado = arrayEmJson($variavel);

	return $resultado;
}

function objectEmJson($objeto)
{
	$class_vars = get_class_vars(get_class($objeto));
	$arrayObjeto = array();
	$namesClass = array();

	$indiceVariable = -1;
	foreach ($class_vars as $name => $value) {
		if ($name == 'variable') $indiceVariable = sizeof($namesClass);
		array_push($namesClass, $name);
	}

	if ($indiceVariable != -1) $namesClass = $objeto->get($namesClass[$indiceVariable]);

	for ($i = 0; $i < sizeof($namesClass); $i++) {
		array_push($arrayObjeto, $namesClass[$i], $objeto->get($namesClass[$i]));
	}

	$verifica = true;
	$primeiro = true;
	$stringArray = "";
	$preStringArray = "";
	foreach ($arrayObjeto as $key => $value) {
		if ($verifica) {
			if ($primeiro) 	$preStringArray = "{\"" . $value . "\":";
			else 			$preStringArray = ",\"" . $value . "\":";
			$verifica = false;
		} else {
			switch (gettype($value)) {
				case 'string':
					$stringArray .= $preStringArray . "\"" . $value . "\"";
					break;
				case 'integer':
					$stringArray .= $preStringArray . $value;
					break;
				case 'double':
					$stringArray .= $preStringArray . $value;
					break;
				case 'floute':
					$stringArray .= $preStringArray . $value;
					break;
				case 'boolean':
					$stringArray .= $value ? $preStringArray . "1" : $preStringArray . "0";
					break;
				case 'object':
					$stringArray .= $preStringArray . objectEmJson($value);
					break;
				case 'array':
					$stringArray .= $preStringArray . arrayEmJson($value);
					break;
				case 'NULL':
					// $stringArray .= $preStringArray.arrayEmJson($value);
					break;
				default:
					$stringArray .= $preStringArray . "\"" . $value . "\"";
			}
			if (gettype($value) != 'NULL') $primeiro = false;
			$verifica = true;
		}
	}
	return $stringArray . "}";
}

function arrayEmJson($array)
{
	$stringArray = "[";
	$primeiro = true;

	foreach ($array as $key => $value) {
		switch (gettype($value)) {
			case 'string':
				if ($primeiro) 	$stringArray .= "\"" . $value . "\"";
				else 			$stringArray .= ",\"" . $value . "\"";
				break;
			case 'interger':
				if ($primeiro)	$stringArray .= $value;
				else 			$stringArray .= "," . $value;
				break;
			case 'int':
				if ($primeiro)	$stringArray .= $value;
				else 			$stringArray .= "," . $value;
				break;
			case 'double':
				if ($primeiro) 	$stringArray .= $value;
				else 			$stringArray .= "," . $value;
				break;
			case 'float':
				if ($primeiro)	$stringArray .= $value;
				else 			$stringArray .= "," . $value;
				break;
			case 'boolean':
				if ($value)		$value = "1";
				else 			$value = "0";
				if ($primeiro) 	$stringArray .= $value;
				else 			$stringArray .= "," . $value;
				break;
			case 'object':
				if ($primeiro) 	$stringArray .= objectEmJson($value);
				else 			$stringArray .= "," . objectEmJson($value);
				break;
			case 'array':
				if ($primeiro)	$stringArray .= arrayEmJson($value);
				else 			$stringArray .= "," . arrayEmJson($value);
				break;
			default:
				$stringArray .= "\"" . $value . "\"";
				break;
		}
		$primeiro = false;
	}
	return $stringArray . "]";
}

function formatarJsonParaHtml($json)
{
	$dados = json_decode($json);

	if ($dados === null && json_last_error() !== JSON_ERROR_NONE) {
		return '<p>Erro ao decodificar o JSON</p>';
	}

	return '<pre>' . htmlspecialchars(json_encode($dados, JSON_PRETTY_PRINT)) . '</pre>';
}

/**********************************************************************************************/
/* OUTRAS FUNÇÕES DA SEVENPAY */
/**********************************************************************************************/
function returnPixel($service, $id_pixel, $event, $options = array())
{
	$pixel = '';
	switch ($service) {
		case 'facebook':
			$pixel = ''
				. "\n<!-- Facebook Pixel Code -->"
				. "\n<script>"
				. "\n!function(f,b,e,v,n,t,s)"
				. "\n{if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
				. "\nn.callMethod.apply(n,arguments):n.queue.push(arguments)};"
				. "\nif(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';"
				. "\nn.queue=[];t=b.createElement(e);t.async=!0;"
				. "\nt.src=v;s=b.getElementsByTagName(e)[0];"
				. "\ns.parentNode.insertBefore(t,s)}(window, document,'script',"
				. "\n'https://connect.facebook.net/en_US/fbevents.js');"
				. "\nfbq('init', '$id_pixel');"
				. "\nfbq('track', '$event'"
				. (!isset($options['paramsEvent']) ? '' : ''
					. ", "
					. (gettype($options['paramsEvent']) == 'string'
						? $options['paramsEvent'] : json_encode($options['paramsEvent'])
					)
				)
				. ");"
				. "\n</script>"
				. "\n<noscript><img height=\"1\" width=\"1\" style=\"display:none\""
				. "\nsrc=\"https://www.facebook.com/tr?id=$id_pixel&ev=$event&noscript=1\""
				. "\n/></noscript>"
				. "\n<!-- End Facebook Pixel Code -->";
			break;
		case 'google':
			$pixel = ''
				. "\n<!-- GoogleAds Pixel Code -->"
				. "\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=$id_pixel&ev=$event\"></script>"
				. "\n<script>"
				. "\nwindow.dataLayer = window.dataLayer || [];"
				. "\nfunction gtag(){dataLayer.push(arguments);}"
				. "\ngtag('js', new Date());"
				. "\ngtag('config', '$id_pixel');"
				. "\ngtag('event', '$event'"
				. (!isset($options['paramsEvent']) ? '' : ''
					. ", "
					. (gettype($options['paramsEvent']) == 'string'
						? $options['paramsEvent'] : json_encode($options['paramsEvent'])
					)
				)
				. ");"
				. "\n</script>"
				. "\n<!-- End GoogleAds Pixel Code -->";
			break;
		case 'tiktok':
			$pixel = ''
				. "\n<!-- TikTokAds Pixel Code -->"
				. "\n<!-- End TikTokAds Pixel Code -->";
			break;
		default:
			$pixel = '';
			break;
	}
	return $pixel;
}

/**********************************************************************************************/
/* OUTRAS FUNÇÕES DO ARQUIVO SANHIDREL funcoes.php */
/**********************************************************************************************/
/** FUNÇÕES COM DATAS */
function formataDatUN($dataUN)
{
	return implode("/", array_reverse(explode("-", $dataUN)));
}

function formataDataBR($dataBR)
{
	return implode("-", array_reverse(explode("/", $dataBR)));
}

function formatarDataMes($dataBR)
{
	$dataBR = explode('/', $dataBR);
	return retornaMes($dataBR[1], 'abr') . "/" . $dataBR[2];
}

function formatarDataMesUN($dataUN)
{
	$dataUN = explode('-', $dataUN);
	return  $dataUN[0] . "-" . retornaMes($dataUN[1], 'abr');
}

function formatarDataPadraoAmericano($data)
{
	if (strpos($data, '.')) 	return implode('-', array_reverse(explode('.', $data)));
	else if (strpos($data, '/'))	return implode('-', array_reverse(explode('/', $data)));
	else 							return $data;
}

function retornaMes($mes, $tipo)
{
	switch ($mes) {
		case  1:
			$mes = $tipo == 'abr' ? 'Jan' : 'Janeiro';
			break;
		case  2:
			$mes = $tipo == 'abr' ? 'Fev' : 'Fevereiro';
			break;
		case  3:
			$mes = $tipo == 'abr' ? 'Mar' : 'Março';
			break;
		case  4:
			$mes = $tipo == 'abr' ? 'Abr' : 'Abril';
			break;
		case  5:
			$mes = $tipo == 'abr' ? 'Mai' : 'Maio';
			break;
		case  6:
			$mes = $tipo == 'abr' ? 'Jun' : 'Junho';
			break;
		case  7:
			$mes = $tipo == 'abr' ? 'Jul' : 'Junlho';
			break;
		case  8:
			$mes = $tipo == 'abr' ? 'Ago' : 'Agosto';
			break;
		case  9:
			$mes = $tipo == 'abr' ? 'Set' : 'Setembro';
			break;
		case 10:
			$mes = $tipo == 'abr' ? 'Out' : 'Outubro';
			break;
		case 11:
			$mes = $tipo == 'abr' ? 'Nov' : 'Novembro';
			break;
		case 12:
			$mes = $tipo == 'abr' ? 'Dez' : 'Dezembro';
			break;
	}
	return $mes;
}

function ordernarArray($array)
{
	for ($i = 0; $i < sizeof($array); $i++) {
		$dataAtual = $array[$i];
		for ($x = $i + 1; $x < sizeof($array); $x++) {
			if ($dataAtual > $array[$x]) {
				$novaData = $dataAtual;
				$array[$i] = $array[$x];
				$dataAtual = $array[$x];
				$array[$x] = $novaData;
			}
		}
	}
	return $array;
}

function removerEspacoDuplo($str)
{
	while (strpos($str, "  ")) $str = str_replace("  ", " ", $str);
	return $str;
}

function formataParaQuery($texto, $tabelaSql, $campoSql)
{
	$texto = explode(" ", $texto);
	$descricaoCompleta = "";
	for ($i = 0; $i < sizeof($texto); $i++) {
		if ($texto[$i] != "") {
			if ($campoSql == "DS_MARCA") {
				$descricaoCompleta .= "
					$tabelaSql.$campoSql LIKE '%' || UPPER('" . $texto[$i] . "') || '%'";
			} else if ($campoSql == "SIGLA_MARCA") {
				$descricaoCompleta .= "
					OR $tabelaSql.$campoSql LIKE '%' || UPPER('" . $texto[$i] . "') || '%'";
			} else {
				$descricaoCompleta .= "
					AND $tabelaSql.$campoSql LIKE '%' || UPPER('" . $texto[$i] . "') || '%'";
			}
		}
	}
	$texto = $descricaoCompleta;
	return $texto;
}

function sanitizeString($str)
{
	header('Content-type: text/html; charset=ISO-8859-1');
	$str = preg_replace('/[áàãâä]/ui', 'a', $str);
	$str = preg_replace('/[éèêë]/ui', 'e', $str);
	$str = preg_replace('/[íìîï]/ui', 'i', $str);
	$str = preg_replace('/[óòõôö]/ui', 'o', $str);
	$str = preg_replace('/[úùûü]/ui', 'u', $str);
	$str = preg_replace('/[ç]/ui', 'c', $str);
	$str = preg_replace('/[ñ]/ui', 'n', $str);
	$str = preg_replace('/[ýÿ]/ui', 'y', $str);
	// $str = preg_replace('/[,(),;:|!"#$%&/=?~^><ªº-]/', '_', $str);
	$str = preg_replace('/[^a-z0-9]/i', ' ', $str);
	$str = preg_replace('/_+/', ' ', $str);
	return $str;
}

function retirarAcento($texto)
{
	// header('Content-type: text/html; charset=ISO-8859-1');
	$texto = str_replace("À", "A", $texto);
	$texto = str_replace("Á", "A", $texto);
	$texto = str_replace("Â", "A", $texto);
	$texto = str_replace("Ã", "A", $texto);
	$texto = str_replace("Ä", "A", $texto);
	$texto = str_replace("È", "E", $texto);
	$texto = str_replace("É", "E", $texto);
	$texto = str_replace("Ê", "E", $texto);
	$texto = str_replace("Ë", "E", $texto);
	$texto = str_replace("Ì", "I", $texto);
	$texto = str_replace("Í", "I", $texto);
	$texto = str_replace("Î", "I", $texto);
	$texto = str_replace("Ï", "I", $texto);
	$texto = str_replace("Ò", "O", $texto);
	$texto = str_replace("Ó", "O", $texto);
	$texto = str_replace("Ô", "O", $texto);
	$texto = str_replace("Õ", "O", $texto);
	$texto = str_replace("Ö", "O", $texto);
	$texto = str_replace("Ù", "U", $texto);
	$texto = str_replace("Ú", "U", $texto);
	$texto = str_replace("Û", "U", $texto);
	$texto = str_replace("Ü", "U", $texto);

	$texto = str_replace("â", "a", $texto);
	$texto = str_replace("á", "a", $texto);
	$texto = str_replace("à", "a", $texto);
	$texto = str_replace("ã", "a", $texto);
	$texto = str_replace("ä", "a", $texto);
	$texto = str_replace("ê", "e", $texto);
	$texto = str_replace("é", "e", $texto);
	$texto = str_replace("è", "e", $texto);
	$texto = str_replace("ë", "e", $texto);
	$texto = str_replace("î", "i", $texto);
	$texto = str_replace("í", "i", $texto);
	$texto = str_replace("ì", "i", $texto);
	$texto = str_replace("ï", "i", $texto);
	$texto = str_replace("õ", "o", $texto);
	$texto = str_replace("ô", "o", $texto);
	$texto = str_replace("ó", "o", $texto);
	$texto = str_replace("ò", "o", $texto);
	$texto = str_replace("ö", "o", $texto);
	$texto = str_replace("û", "u", $texto);
	$texto = str_replace("ú", "u", $texto);
	$texto = str_replace("ù", "u", $texto);
	$texto = str_replace("ü", "u", $texto);

	$texto = str_replace("Ç", "C", $texto);
	$texto = str_replace("Ñ", "N", $texto);
	$texto = str_replace("Ý", "Y", $texto);

	$texto = str_replace("ç", "c", $texto);
	$texto = str_replace("ñ", "n", $texto);
	$texto = str_replace("ý", "y", $texto);
	$texto = str_replace("ÿ", "y", $texto);

	$texto = str_replace("nº", ",", $texto);
	$texto = str_replace("Nº", ",", $texto);
	$texto = str_replace("'", "", $texto);
	$texto = str_replace("ª", "", $texto);
	$texto = str_replace("º", "^0", $texto);
	$texto = str_replace("%", "", $texto);
	$texto = str_replace("&", "", $texto);

	$texto = str_replace("§", "", $texto);
	$texto = str_replace("°", "^0", $texto);
	$texto = str_replace("€", "", $texto);

	$texto = str_replace("•", "", $texto);
	$texto = str_replace("¤", "", $texto);
	$texto = str_replace("¶", "", $texto);
	$texto = str_replace("#", "", $texto);
	$texto = str_replace("$", "", $texto);
	// $texto = str_replace(":", "", $texto);
	$texto = str_replace("<", "", $texto);
	$texto = str_replace(">", "", $texto);
	$texto = str_replace("?", "", $texto);
	$texto = str_replace("^", "", $texto);
	$texto = str_replace("`", "", $texto);
	$texto = str_replace("{", "", $texto);
	$texto = str_replace("|", "", $texto);
	$texto = str_replace("}", "", $texto);
	$texto = str_replace("~", "", $texto);
	$texto = str_replace("æ", "", $texto);
	$texto = str_replace("Æ", "", $texto);
	$texto = str_replace("ø", "", $texto);
	$texto = str_replace("£", "", $texto);
	$texto = str_replace("Ø", " diametro ", $texto);
	$texto = str_replace("×", "", $texto);
	$texto = str_replace("ƒ", "", $texto);
	$texto = str_replace("¿", "", $texto);
	$texto = str_replace("®", "", $texto);
	$texto = str_replace("¬", "", $texto);
	$texto = str_replace("½", "", $texto);
	$texto = str_replace("¼", "", $texto);
	$texto = str_replace("¡", "", $texto);
	$texto = str_replace("«", "", $texto);
	$texto = str_replace("»", "", $texto);
	$texto = str_replace("©", "", $texto);
	$texto = str_replace("¢", "", $texto);
	$texto = str_replace("¥", "", $texto);
	$texto = str_replace("ð", "", $texto);
	$texto = str_replace("Ð", "", $texto);
	$texto = str_replace("¯", "", $texto);
	$texto = str_replace("ß", "", $texto);
	$texto = str_replace("µ", "", $texto);
	$texto = str_replace("Þ", "", $texto);
	$texto = str_replace("þ", "", $texto);
	$texto = str_replace("´", "", $texto);
	$texto = str_replace("±", "", $texto);
	$texto = str_replace("¾", "", $texto);
	$texto = str_replace("÷", "", $texto);
	$texto = str_replace("¸", "", $texto);
	$texto = str_replace("°", "", $texto);
	$texto = str_replace("¨", "", $texto);
	$texto = str_replace("¹", "^1", $texto);
	$texto = str_replace("²", "^2", $texto);
	$texto = str_replace("³", "^3", $texto);
	$texto = str_replace("¦", "", $texto);

	return $texto;
}

function replaceUnicode($texto)
{
	$replace = array(
		' ',
		'¡',
		'¢',
		'£',
		'¤',
		'¥',
		'¦',
		'§',
		'¨',
		'©',
		'ª',
		'«',
		'¬',
		'®',
		'¯',
		'°',
		'±',
		'²',
		'³',
		'´',
		'µ',
		'¶',
		'·',
		'¸',
		'¹',
		'º',
		'»',
		'¼',
		'½',
		'¾',
		'¿',
		'À',
		'Á',
		'Â',
		'Ã',
		'Ä',
		'Å',
		'Æ',
		'Ç',
		'È',
		'É',
		'Ê',
		'Ë',
		'Ì',
		'Í',
		'Î',
		'Ï',
		'Ð',
		'Ñ',
		'Ò',
		'Ó',
		'Ô',
		'Õ',
		'Ö',
		'×',
		'Ø',
		'Ù',
		'Ú',
		'Û',
		'Ü',
		'Ý',
		'Þ',
		'ß',
		'à',
		'á',
		'â',
		'ã',
		'ä',
		'å',
		'æ',
		'ç',
		'è',
		'é',
		'ê',
		'ë',
		'ì',
		'í',
		'î',
		'ï',
		'ð',
		'ñ',
		'ò',
		'ó',
		'ô',
		'õ',
		'ö',
		'÷',
		'ø',
		'ù',
		'ú',
		'û',
		'ü',
		'ý',
		'þ',
		'ÿ'
	);

	$search = array(
		'u00A0',
		'u00A1',
		'u00A2',
		'u00A3',
		'u00A4',
		'u00A5',
		'u00A6',
		'u00A7',
		'u00A8',
		'u00A9',
		'u00AA',
		'u00AB',
		'u00AC',
		'u00AE',
		'u00AF',
		'u00B0',
		'u00B1',
		'u00B2',
		'u00B3',
		'u00B4',
		'u00B5',
		'u00B6',
		'u00B7',
		'u00B8',
		'u00B9',
		'u00BA',
		'u00BB',
		'u00BC',
		'u00BD',
		'u00BE',
		'u00BF',
		'u00C0',
		'u00C1',
		'u00C2',
		'u00C3',
		'u00C4',
		'u00C5',
		'u00C6',
		'u00C7',
		'u00C8',
		'u00C9',
		'u00CA',
		'u00CB',
		'u00CC',
		'u00CD',
		'u00CE',
		'u00CF',
		'u00D0',
		'u00D1',
		'u00D2',
		'u00D3',
		'u00D4',
		'u00D5',
		'u00D6',
		'u00D7',
		'u00D8',
		'u00D9',
		'u00DA',
		'u00DB',
		'u00DC',
		'u00DD',
		'u00DE',
		'u00DF',
		'u00E0',
		'u00E1',
		'u00E2',
		'u00E3',
		'u00E4',
		'u00E5',
		'u00E6',
		'u00E7',
		'u00E8',
		'u00E9',
		'u00EA',
		'u00EB',
		'u00EC',
		'u00ED',
		'u00EE',
		'u00EF',
		'u00F0',
		'u00F1',
		'u00F2',
		'u00F3',
		'u00F4',
		'u00F5',
		'u00F6',
		'u00F7',
		'u00F8',
		'u00F9',
		'u00FA',
		'u00FB',
		'u00FC',
		'u00FD',
		'u00FE',
		'u00FF'
	);

	$search2 = array(
		'\\u00A0',
		'\\u00A1',
		'\\u00A2',
		'\\u00A3',
		'\\u00A4',
		'\\u00A5',
		'\\u00A6',
		'\\u00A7',
		'\\u00A8',
		'\\u00A9',
		'\\u00AA',
		'\\u00AB',
		'\\u00AC',
		'\\u00AE',
		'\\u00AF',
		'\\u00B0',
		'\\u00B1',
		'\\u00B2',
		'\\u00B3',
		'\\u00B4',
		'\\u00B5',
		'\\u00B6',
		'\\u00B7',
		'\\u00B8',
		'\\u00B9',
		'\\u00BA',
		'\\u00BB',
		'\\u00BC',
		'\\u00BD',
		'\\u00BE',
		'\\u00BF',
		'\\u00C0',
		'\\u00C1',
		'\\u00C2',
		'\\u00C3',
		'\\u00C4',
		'\\u00C5',
		'\\u00C6',
		'\\u00C7',
		'\\u00C8',
		'\\u00C9',
		'\\u00CA',
		'\\u00CB',
		'\\u00CC',
		'\\u00CD',
		'\\u00CE',
		'\\u00CF',
		'\\u00D0',
		'\\u00D1',
		'\\u00D2',
		'\\u00D3',
		'\\u00D4',
		'\\u00D5',
		'\\u00D6',
		'\\u00D7',
		'\\u00D8',
		'\\u00D9',
		'\\u00DA',
		'\\u00DB',
		'\\u00DC',
		'\\u00DD',
		'\\u00DE',
		'\\u00DF',
		'\\u00E0',
		'\\u00E1',
		'\\u00E2',
		'\\u00E3',
		'\\u00E4',
		'\\u00E5',
		'\\u00E6',
		'\\u00E7',
		'\\u00E8',
		'\\u00E9',
		'\\u00EA',
		'\\u00EB',
		'\\u00EC',
		'\\u00ED',
		'\\u00EE',
		'\\u00EF',
		'\\u00F0',
		'\\u00F1',
		'\\u00F2',
		'\\u00F3',
		'\\u00F4',
		'\\u00F5',
		'\\u00F6',
		'\\u00F7',
		'\\u00F8',
		'\\u00F9',
		'\\u00FA',
		'\\u00FB',
		'\\u00FC',
		'\\u00FD',
		'\\u00FE',
		'\\u00FF'
	);

	for ($i = 0; $i < sizeof($search); $i++) {
		$texto = str_replace($search2[$i], $replace[$i], $texto);
		$texto = str_replace(strtolower($search2[$i]), $replace[$i], $texto);

		$texto = str_replace($search[$i], $replace[$i], $texto);
		$texto = str_replace(strtolower($search[$i]), $replace[$i], $texto);
	}
	return $texto;
}

function decodificarUtf8($texto)
{
	$texto = str_replace("â‚¬", "€", $texto);
	$texto = str_replace("â€š", "‚", $texto);
	$texto = str_replace("â€ž", "„", $texto);
	$texto = str_replace("â€¦", "…", $texto);
	$texto = str_replace("â€¡", "‡", $texto);
	$texto = str_replace("â€°", "‰", $texto);
	$texto = str_replace("â€¹", "‹", $texto);
	$texto = str_replace("â€˜", "‘", $texto);
	$texto = str_replace("â€™", "’", $texto);
	$texto = str_replace("â€œ", "“", $texto);
	$texto = str_replace("â€¢", "•", $texto);
	$texto = str_replace("â€“", "–", $texto);
	$texto = str_replace("â€”", "—", $texto);
	$texto = str_replace("â„¢", "™", $texto);
	$texto = str_replace("â€º", "›", $texto);

	$texto = str_replace("Ã€", "À", $texto);
	$texto = str_replace("Ã‚", "Â", $texto);
	$texto = str_replace("Æ’", "ƒ", $texto);
	$texto = str_replace("Ãƒ", "Ã", $texto);
	$texto = str_replace("Ã„", "Ä", $texto);
	$texto = str_replace("Ã…", "Å", $texto);
	$texto = str_replace("â€", "†", $texto);
	$texto = str_replace("Ã†", "Æ", $texto);
	$texto = str_replace("Ã‡", "Ç", $texto);
	$texto = str_replace("Ë†", "ˆ", $texto);
	$texto = str_replace("Ãˆ", "È", $texto);
	$texto = str_replace("Ã‰", "É", $texto);
	$texto = str_replace("ÃŠ", "Ê", $texto);
	$texto = str_replace("Ã‹", "Ë", $texto);
	$texto = str_replace("Å’", "Œ", $texto);
	$texto = str_replace("ÃŒ", "Ì", $texto);
	$texto = str_replace("Å½", "Ž", $texto);
	$texto = str_replace("ÃŽ", "Î", $texto);
	$texto = str_replace("Ã‘", "Ñ", $texto);
	$texto = str_replace("Ã’", "Ò", $texto);
	$texto = str_replace("Ã“", "Ó", $texto);
	$texto = str_replace("â€", "”", $texto);
	$texto = str_replace("Ã”", "Ô", $texto);
	$texto = str_replace("Ã•", "Õ", $texto);
	$texto = str_replace("Ã–", "Ö", $texto);
	$texto = str_replace("Ã—", "×", $texto);
	$texto = str_replace("Ëœ", "˜", $texto);
	$texto = str_replace("Ã˜", "Ø", $texto);
	$texto = str_replace("Ã™", "Ù", $texto);
	$texto = str_replace("Å¡", "š", $texto);
	$texto = str_replace("Ãš", "Ú", $texto);
	$texto = str_replace("Ã›", "Û", $texto);
	$texto = str_replace("Å“", "œ", $texto);
	$texto = str_replace("Ãœ", "Ü", $texto);
	$texto = str_replace("Å¾", "ž", $texto);
	$texto = str_replace("Ãž", "Þ", $texto);
	$texto = str_replace("Å¸", "Ÿ", $texto);
	$texto = str_replace("ÃŸ", "ß", $texto);
	$texto = str_replace("Â¡", "¡", $texto);
	$texto = str_replace("Ã¡", "á", $texto);
	$texto = str_replace("Â¢", "¢", $texto);
	$texto = str_replace("Ã¢", "â", $texto);
	$texto = str_replace("Â£", "£", $texto);
	$texto = str_replace("Ã£", "ã", $texto);
	$texto = str_replace("Â¤", "¤", $texto);
	$texto = str_replace("Ã¤", "ä", $texto);
	$texto = str_replace("Â¥", "¥", $texto);
	$texto = str_replace("Ã¥", "å", $texto);
	$texto = str_replace("Â¦", "¦", $texto);
	$texto = str_replace("Ã¦", "æ", $texto);
	$texto = str_replace("Â§", "§", $texto);
	$texto = str_replace("Ã§", "ç", $texto);
	$texto = str_replace("Â¨", "¨", $texto);
	$texto = str_replace("Ã¨", "è", $texto);
	$texto = str_replace("Â©", "©", $texto);
	$texto = str_replace("Ã©", "é", $texto);
	$texto = str_replace("Âª", "ª", $texto);
	$texto = str_replace("Ãª", "ê", $texto);
	$texto = str_replace("Â«", "«", $texto);
	$texto = str_replace("Ã«", "ë", $texto);
	$texto = str_replace("Â¬", "¬", $texto);
	$texto = str_replace("Ã¬", "ì", $texto);
	$texto = str_replace("Â®", "®", $texto);
	$texto = str_replace("Ã®", "î", $texto);
	$texto = str_replace("Â¯", "¯", $texto);
	$texto = str_replace("Ã¯", "ï", $texto);
	$texto = str_replace("Â°", "°", $texto);
	$texto = str_replace("Ã°", "ð", $texto);
	$texto = str_replace("Â±", "±", $texto);
	$texto = str_replace("Ã±", "ñ", $texto);
	$texto = str_replace("Â²", "²", $texto);
	$texto = str_replace("Ã²", "ò", $texto);
	$texto = str_replace("Â³", "³", $texto);
	$texto = str_replace("Ã³", "ó", $texto);
	$texto = str_replace("Â´", "´", $texto);
	$texto = str_replace("Ã´", "ô", $texto);
	$texto = str_replace("Âµ", "µ", $texto);
	$texto = str_replace("Ãµ", "õ", $texto);
	$texto = str_replace("Â¶", "¶", $texto);
	$texto = str_replace("Ã¶", "ö", $texto);
	$texto = str_replace("Â·", "·", $texto);
	$texto = str_replace("Ã·", "÷", $texto);
	$texto = str_replace("Â¸", "¸", $texto);
	$texto = str_replace("Ã¸", "ø", $texto);
	$texto = str_replace("Â¹", "¹", $texto);
	$texto = str_replace("Ã¹", "ù", $texto);
	$texto = str_replace("Âº", "º", $texto);
	$texto = str_replace("Ãº", "ú", $texto);
	$texto = str_replace("Â»", "»", $texto);
	$texto = str_replace("Ã»", "û", $texto);
	$texto = str_replace("Â¼", "¼", $texto);
	$texto = str_replace("Ã¼", "ü", $texto);
	$texto = str_replace("Â½", "½", $texto);
	$texto = str_replace("Ã½", "ý", $texto);
	$texto = str_replace("Â¾", "¾", $texto);
	$texto = str_replace("Ã¾", "þ", $texto);
	$texto = str_replace("Â¿", "¿", $texto);
	$texto = str_replace("Ã¿", "ÿ", $texto);
	$texto = str_replace("Å",  "Š", $texto);

	$texto = str_replace("Ã­", "í", $texto);
	$texto = str_replace("Ã", "Á", $texto);
	$texto = str_replace("Ã", "à", $texto);
	$texto = str_replace("Ã", "Ý", $texto);
	$texto = str_replace("Ã", "Í", $texto);
	$texto = str_replace("Ã", "Ï", $texto);
	$texto = str_replace("Ã", "Ð", $texto);

	return $texto;
}

function criptografar($chave)
{
	$chave = "$chave";
	$num = "";
	for ($i = 0; $i < strlen($chave); $i++) {
		$num .= subNumCrip($chave[$i]);
	}
	return parseHex($num);
}

function descriptografar($codigo, $chave)
{
	$codigoQuebra = parseDec($codigo);
	$codigo = "";
	while (strlen($codigoQuebra) >= $chave) {
		$codigo .= subNumDesCrip(substr($codigoQuebra, 0, $chave));
		$codigoQuebra = substr($codigoQuebra, $chave, strlen($codigoQuebra));
	}
	return $codigo;
}

function subNumCrip($num)
{
	switch ($num) {
		case 0:
			$num = 21;
			break;
		case 1:
			$num = 42;
			break;
		case 2:
			$num = 59;
			break;
		case 3:
			$num = 37;
			break;
		case 4:
			$num = 97;
			break;
		case 5:
			$num = 96;
			break;
		case 6:
			$num = 84;
			break;
		case 7:
			$num = 48;
			break;
		case 8:
			$num = 23;
			break;
		case 9:
			$num = 25;
			break;
	}
	return $num;
}

function subNumDesCrip($num)
{
	switch ($num) {
		case 21:
			$num = 0;
			break;
		case 42:
			$num = 1;
			break;
		case 59:
			$num = 2;
			break;
		case 37:
			$num = 3;
			break;
		case 97:
			$num = 4;
			break;
		case 96:
			$num = 5;
			break;
		case 84:
			$num = 6;
			break;
		case 48:
			$num = 7;
			break;
		case 23:
			$num = 8;
			break;
		case 25:
			$num = 9;
			break;
	}
	return $num;
}

function parseHex($num)
{
	$hex = "";
	$hexVez = "";
	while ($num >= 16) {
		$hexVez = ($num % 16);
		$num = (int) ($num / 16);
		$hex .= validaAcimaDez($hexVez);
	}
	return $hex . validaAcimaDez($num);
}

function validaAcimaDez($num)
{
	switch ($num) {
		case 10:
			$num = 'A';
			break;
		case 11:
			$num = 'B';
			break;
		case 12:
			$num = 'C';
			break;
		case 13:
			$num = 'D';
			break;
		case 14:
			$num = 'E';
			break;
		case 15:
			$num = 'F';
			break;
	}
	return $num;
}

function parseDec($hex)
{
	$numVez = 0;
	$num = 0;
	for ($i = 0; $i < strlen($hex); $i++) {
		switch ($hex[$i]) {
			case 'A':
				$numVez = 10;
				break;
			case 'B':
				$numVez = 11;
				break;
			case 'C':
				$numVez = 12;
				break;
			case 'D':
				$numVez = 13;
				break;
			case 'E':
				$numVez = 14;
				break;
			case 'F':
				$numVez = 15;
				break;
			default:
				$numVez = (int) $hex[$i];
		}
		$num += ($numVez * pot(16, $i));
	}
	return $num;
}

function pot($num, $exp)
{
	$total = 1;
	for ($i = 0; $i < $exp; $i++) $total *= $num;
	return $total;
}
