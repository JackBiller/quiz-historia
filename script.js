var grupo_Global = [];
var perguntas_Global = [];
var respostas_Global = [];
var indexPergunta_Global = 0;
var finalizouQuiz = false;

function buscarGrupos() {
	$.ajax({
		url: './controller.php',
		type: 'POST',
		data: { getGrupos: true },
	}).done(function (data) {
		console.log(data);
		data = JSON.parse(data);
		console.log(data);
		grupo_Global = data;

		let html = '';
		for (let i = 0; i < data.length; i++) {
			html += '<li>' +
				'<a href="#" class="menu-toggle" data-target="#menu' + (i + 1) + '">' +
				data[i].nome_grupo + ' <i class="fas fa-chevron-right"></i>' +
				'</a>' +
				'<ul id="menu' + (i + 1) + '" class="sub-menu nav nav-pills nav-stacked">' +
				data[i].categorias.map(categoria => {
					return '<li onclick="selecionaCategoria(' + categoria.id_categoria + ')">' +
						categoria.nome_categoria +
						'</li>';
				}).join('<hr>') +
				'</ul>' +
				'</li>';
		}
		console.log(html);
		$("#menu-toggle").html(html);
		$('.menu-toggle').click(function (e) {
			e.preventDefault();
			var target = $(this).data('target');
			var icon = $(this).find('i');
			$(target).slideToggle();
			icon.toggleClass('fa-chevron-right fa-chevron-down');
		});
	});
}

function selecionaCategoria(id_categoria) {
	if (perguntas_Global.length > 0 &&
		indexPergunta_Global != 0 &&
		!finalizouQuiz &&
		!confirm('Você deseja abandonar o questionário atual?')
	) {
		return;
	}

	let ids_perguntas = '';
	for (let i = 0; i < grupo_Global.length; i++) {
		let categorias = grupo_Global[i].categorias;
		for (let j = 0; j < categorias.length; j++) {
			if (categorias[j].id_categoria == id_categoria) {
				ids_perguntas = categorias[j].ids_pergunta;
			}
		}
	}

	if (ids_perguntas == null) {
		alert('Funcionalidade não implementada');
		return;
	}
	let perguntasArray = ids_perguntas.split(',').map(Number);
	perguntasArray = perguntasArray.sort(() => Math.random() - 0.5).slice(0, 10);

	$.ajax({
		url: './controller.php',
		type: 'POST',
		data: { getPerguntas: true, ids_perguntas: perguntasArray.join(',') },
	}).done(function (data) {
		console.log(data);
		data = JSON.parse(data);
		console.log(data);
		perguntas_Global = data;
		perguntas_Global = perguntas_Global.map(pergunta => {
			pergunta.respostas = pergunta.respostas.sort(() => Math.random() - 0.5);
			return pergunta;
		});
		respostas_Global = [];
		indexPergunta_Global = 0;
		finalizouQuiz = false;
		$("#quizSend").hide();
		$('#quiz').html('' +
			'<a id="prevBtn" class="btnNavQuiz" onclick="anteriorQuestao()">' +
			'<i class="fas fa-arrow-left"></i>' +
			'</a>' +
			'<div class="progress" id="idProgress">' +
			'<div class="progress-bar" id="idProgressBar" role="progressbar"></div>' +
			'</div>' +
			'<a id="nextBtn" class="btnNavQuiz" onclick="proximaQuestao()">' +
			'<i class="fas fa-arrow-right"></i>' +
			'</a>' +
			'<div class="question">' +
			'<h2>Pergunta <span id="numberQuestion"></span></h2>' +
			'<p id="enunciado"></p>' +
			'<div id="resposta" class="options"></div>' +
			'</div>'
		);
		selecionaPergunta();
	});
}

function selecionaPergunta() {
	if (indexPergunta_Global >= perguntas_Global.length) {
		$("#quizSend").show();
		return;
	}
	$("#idProgressBar").css('width', ((indexPergunta_Global + 1) / perguntas_Global.length * 100) + '%');
	$('#numberQuestion').text(indexPergunta_Global + 1);
	$('#enunciado').text(perguntas_Global[indexPergunta_Global].enunciado);
	$('#resposta').html(perguntas_Global[indexPergunta_Global].respostas
		.map((resposta, index) => {
			let letra = ['a', 'b', 'c', 'd', 'e'][index];
			let checked = '';
			if (respostas_Global[indexPergunta_Global] != undefined &&
				respostas_Global[indexPergunta_Global] == resposta.id_resposta
			) {
				checked = 'checked';
			}
			return '<label>' +
				'<input onclick="proximaQuestao(true)" type="radio" name="question" value="' + resposta.id_resposta + '" ' + checked + '>' +
				'&nbsp;' + letra + ') ' + resposta.resposta +
				'</label>'
		}).join('')
	);
}

function anteriorQuestao() {
	indexPergunta_Global--;
	if (indexPergunta_Global < 0) indexPergunta_Global = 0;
	selecionaPergunta();
}

function proximaQuestao(delay = false) {
	let selectedValue = $('input[name="question"]:checked').val();
	if (!selectedValue) {
		alert('Selecione uma resposta');
		return;
	}
	indexPergunta_Global++;
	if (respostas_Global.length < indexPergunta_Global) {
		respostas_Global.push(selectedValue);
	} else {
		respostas_Global[indexPergunta_Global - 1] = selectedValue;
	}
	setTimeout(() => {
		selecionaPergunta();
	}, delay ? 500 : 0);
}

function montaResultado() {
	let acertos = 0;
	let detalhes = [];
	for (let i = 0; i < perguntas_Global.length; i++) {
		let resposta = perguntas_Global[i].respostas.find(r => r.id_resposta == respostas_Global[i]);
		let respostaCerta = perguntas_Global[i].respostas.find(r => r.correta == 1);
		let acertou = respostas_Global[i] == respostaCerta.id_resposta;
		detalhes.push(
			'<div>' +
			'<h4>' + perguntas_Global[i].enunciado + '</h4>' +
			'<p>' +
			'<strong>Sua resposta:</strong> ' + resposta.resposta +
			(acertou
				? ''
				: '<br><strong style="color:red;">Resposta incorreta</strong>' +
				'<br>' +
				'<strong>Resposta correta:</strong> ' + respostaCerta.resposta
			) +
			'</p>' +
			'</div>'
		);
		if (respostas_Global[i] == respostaCerta.id_resposta) {
			acertos++;
		}
	}
	let html = '<h2>Resultado</h2>' +
		'<h3>Você acertou ' + acertos + ' de ' + perguntas_Global.length + ' questões</h3>'+
		detalhes.join('<br>');
		;
	$('#quiz').html(html);
	$("#quizSend").hide();
	finalizouQuiz = true;
}

$(document).ready(() => buscarGrupos());