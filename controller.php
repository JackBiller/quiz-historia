<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/./constConfig.php';
require_once __DIR__ . "/./funcoes.php";

$pdo = getConection();

if (!empty($_REQUEST['getGrupos'])) {
	$sql = "SELECT	g.id_grupo
			, 		g.nome_grupo
			, 		c.id_categoria
			, 		c.nome_categoria
			, 		(
						SELECT GROUP_CONCAT(p.id_pergunta)
						FROM perguntas p
						WHERE c.id_categoria = p.id_categoria
					) AS ids_pergunta
			FROM grupo g
			INNER JOIN categorias c ON g.id_grupo = c.id_grupo
			WHERE g.ck_inativo = 0
			AND c.ck_inativo = 0";
	$result = padraoResultado($pdo, $sql);

	function findGrupo($grupos, $id_grupo)
	{
		for ($i = 0; $i < sizeof($grupos); $i++) {
			if ($grupos[$i]['id_grupo'] == $id_grupo) {
				return $i;
			}
		}
		return -1;
	}

	$grupos = [];
	for ($i = 0; $i < sizeof($result); $i++) {
		$row = $result[$i];
		if (findGrupo($grupos, $row->get('id_grupo')) == -1) {
			array_push($grupos, [
				'id_grupo' => $row->get('id_grupo'),
				'nome_grupo' => $row->get('nome_grupo'),
				'categorias' => [],
			]);
		}
		if (!empty($row->get('id_categoria'))) {
			array_push($grupos[findGrupo($grupos, $row->get('id_grupo'))]['categorias'], [
				'id_categoria' => $row->get('id_categoria'),
				'nome_categoria' => $row->get('nome_categoria'),
				'ids_pergunta' => $row->get('ids_pergunta'),
			]);
		}
	}

	echo json_encode($grupos);
}

if (!empty($_REQUEST['getCategorias'])) {
	$filterGrupo = '';
	if (!empty($_REQUEST['grupo'])) {
		$filterGrupo = "AND id_grupo = " . $_REQUEST['grupo'];
	}

	$sql = "SELECT 	id_categoria
			, 		nome_categoria
			, 		id_grupo
			FROM 	categorias
			WHERE 	1 = 1
			$filterGrupo";
	$result = padraoResultado($pdo, $sql);
	echo toJson($result);
}

if (!empty($_REQUEST['getPerguntas'])) {
	$filterCategoria = '';
	if (!empty($_REQUEST['categoria'])) {
		$filterCategoria = "AND perguntas.id_categoria = " . $_REQUEST['categoria'];
	}

	$filterPerguntas = '';
	if (!empty($_REQUEST['ids_perguntas'])) {
		$filterPerguntas = "AND perguntas.id_pergunta IN (" . $_REQUEST['ids_perguntas'] . ")";
	}

	$sql = "SELECT 	perguntas.id_pergunta
			, 		perguntas.enunciado
			, 		perguntas.id_categoria
			, 		respostas.id_resposta
			, 		respostas.resposta
			, 		respostas.correta
			FROM 	perguntas
			INNER JOIN respostas
				ON 	perguntas.id_pergunta = respostas.id_pergunta
			WHERE 	1 = 1
			$filterCategoria
			$filterPerguntas";
	$result = padraoResultado($pdo, $sql);

	function findPergunta($perguntas, $id_pergunta)
	{
		for ($i = 0; $i < sizeof($perguntas); $i++) {
			if ($perguntas[$i]['id_pergunta'] == $id_pergunta) {
				return $i;
			}
		}
		return -1;
	}

	$perguntas = [];
	for ($i = 0; $i < sizeof($result); $i++) {
		$row = $result[$i];
		if (findPergunta($perguntas, $row->get('id_pergunta')) == -1) {
			array_push($perguntas, [
				'id_pergunta' => $row->get('id_pergunta'),
				'enunciado' => $row->get('enunciado'),
				'id_categoria' => $row->get('id_categoria'),
				'respostas' => [],
			]);
		}
		array_push($perguntas[findPergunta($perguntas, $row->get('id_pergunta'))]['respostas'], [
			'id_resposta' => $row->get('id_resposta'),
			'resposta' => $row->get('resposta'),
			'correta' => $row->get('correta'),
		]);
	}


	echo json_encode($perguntas);
}

if (!empty($_REQUEST['getRespostas'])) {
	$filterPergunta = '';
	if (!empty($_REQUEST['perguntas'])) {
		$filterPergunta = "AND id_pergunta IN (" . $_REQUEST['pergunta'] . ")";
	}

	$sql = "SELECT 	id_resposta
			, 		id_pergunta
			, 		resposta
			, 		correta
			FROM 	respostas
			WHERE 	1 = 1
			$filterPergunta";
	$result = padraoResultado($pdo, $sql);
	echo toJson($result);
}
