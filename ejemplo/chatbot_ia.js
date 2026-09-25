/**
 * Módulo Chatbot IA RAG Ligero (Grep + Gemini) - SPA UVA (#ia)
 * @auth Antigravity AI
 * @date 2026-08-14
 */
var objChatbotIA = {
	url: "/common/ajaxPages/getInfojson.jsp",
	historial: [],

	initUva: function () {
		let container = "divPgContentContent";
		$("#" + container).empty();
		jsUtils.ajaxs.killAllRqs();
		objChatbotIA.historial = [];
		objChatbotIA.pintarPlantilla($("#" + container));
		objChatbotIA.initEvents();
	},

	pintarPlantilla: function (contenedor) {
		// Cálculo único de altura dinámica al renderizar la pantalla por primera vez
		let windowHeight = $(window).height() || window.innerHeight || 600;
		// Descontar header superior de la app (~80px) y barra de input inferior (~80px)
		let chatBoxHeight = Math.max(400, windowHeight - 170);

		let html = `
			<div class="row">
				<div class="col-xs-12 col-12 col-md-8 col-md-offset-2 offset-md-2">
					<div id="divChatBox" style="height: ${chatBoxHeight}px; overflow-y: auto; border: 1px solid #e3e6f0; padding: 18px; background-color: #f8f9fc; border-radius: 8px;" class="shadow-sm my-2">
						<div class="alert alert-primary shadow-sm">
							<h5 class="text-primary mb-2">
								<i class="fa-solid fa-robot"></i> Asistente Virtual IA &bull; Base de Conocimiento
							</h5>
							<p class="mb-1">
								<b>&iexcl;Hola!</b> Soy tu asistente inteligente para la gesti&oacute;n y consulta de procesos administrativos e institucionales de la Universidad.
							</p>
							<small class="text-muted">
								<i class="fa-solid fa-circle-check text-success"></i> Consulta en tiempo real sobre normativas, equivalencias, matr&iacute;culas, roles y soporte t&eacute;cnico. Escribe tu pregunta abajo para comenzar.
							</small>
						</div>
					</div>
					
					<div class="row row-cols-md-auto g-3 align-items-center mb-2">
						<div class="col-xs-12 col-12 w-100">
							<div class="input-group">
								<input type="text" id="txtPreguntaIA" class="form-control form-control-lg" placeholder="Escribe tu pregunta aqu&iacute; (ej: &iquest;C&oacute;mo funcionan los permisos autom&aacute;ticos?)..." autocomplete="off" />
								<span class="input-group-btn input-group-append">
									<button type="button" id="btnEnviarIA" class="btn btn-primary btn-lg pull-right float-end">
										<i class="fa-solid fa-paper-plane"></i>
									</button>
								</span>
							</div>
						</div>
					</div>
				</div>
			</div>`;
		contenedor.html(html);
	},

	initEvents: function () {
		$("#txtPreguntaIA").on("keyup", function (e) {
			if (e.keyCode === 13 || e.which === 13) {
				e.preventDefault();
				objChatbotIA.enviarPregunta();
			}
		});

		$("#btnEnviarIA").on("click", function () {
			objChatbotIA.enviarPregunta();
		});
	},

	enviarPregunta: function () {
		let pregunta = $("#txtPreguntaIA").val().trim();
		if (pregunta.length === 0) {
			return;
		}

		let val = new Validador();
		let pasos = [
			{ id: "txtPreguntaIA", tipo: "solocaracteresnormales", error: "La pregunta debe contener caracteres normales sin s&iacute;mbolos especiales." }
		];

		if (!val.validar(pasos)) {
			return;
		}
		
		val.text = '';
		val.button = $("#btnEnviarIA");
		val.enabled();
		val.spinner();
		$("#txtPreguntaIA").val("");

		// Pintar mensaje del usuario
		let htmlUser = `
			<div class="d-flex justify-content-end mb-3 text-end pull-right w-100" style="display: flex; justify-content: flex-end;">
				<div style="max-width: 75%; background-color: #4e73df; color: white; padding: 10px 15px; border-radius: 12px 12px 0px 12px; font-size: 14px;" class="shadow-sm">
					<b><i class="fa-solid fa-user"></i> T&uacute;:</b><br>${pregunta}
				</div>
			</div>
			<div style="clear: both;"></div>`;
		$("#divChatBox").append(htmlUser);

		// Indicador de carga en la caja del chat
		let msgId = "msgLoading_" + new Date().getTime();
		let htmlLoading = `
			<div id="${msgId}" class="d-flex justify-content-start mb-3 pull-left w-100" style="display: flex; justify-content: flex-start;">
				<div style="max-width: 80%; background-color: #ffffff; border: 1px solid #dddfeb; padding: 12px 16px; border-radius: 12px 12px 12px 0px; font-size: 14px;" class="shadow-sm">
					<i class="fa-solid fa-spinner fa-spin text-primary"></i>
					<i class="fa-solid fa-brain fa-beat text-info ms-1 me-2" style="--fa-animation-duration: 1.5s;"></i>
					<span class="text-secondary fst-italic">Analizando pregunta, extrayendo sin&oacute;nimos y consultando la base de conocimiento...</span>
				</div>
			</div>
			<div style="clear: both;"></div>`;
		$("#divChatBox").append(htmlLoading);

		let data = {
			"op": 218331, /*op=218331&*/
			"pregunta": pregunta,
			"historial": JSON.stringify(objChatbotIA.historial.slice(-10))
		};

		let json = {
			"msgId": msgId,
			"val": val,
			"pregunta": pregunta
		};

		jsUtils.ajaxs.exec({
			"url": objChatbotIA.url,
			"data": data,
			"val": val,
			"doneCB": function (res) {
				objChatbotIA.responderPreguntaCB(res, json);
			},
			"doneCBError": function (res) {
				val.enabled();
			}
		});
	},

	responderPreguntaCB: function (res, json) {
		if (json && json.msgId) {
			$("#" + json.msgId).remove();
		}
		if (json && json.val) {
			json.val.enabled();
		}

		// Registrar en historial local (máximo 10 turnos de conversación)
		if (json && json.pregunta && res.respuesta) {
			objChatbotIA.historial.push({
				"rol": "user",
				"texto": json.pregunta
			});
			objChatbotIA.historial.push({
				"rol": "model",
				"texto": res.respuesta
			});
			if (objChatbotIA.historial.length > 10) {
				objChatbotIA.historial = objChatbotIA.historial.slice(-10);
			}
		}

		// Badges de Palabras Clave y Sinónimos
		let badgesKw = "";
		if (res.keywords && res.keywords.length > 0) {
			badgesKw = "<div class='mb-2'>";
			for (let i = 0; i < res.keywords.length; i++) {
				badgesKw += `<span class="badge bg-secondary text-bg-secondary label label-default me-1 ms-1" style="font-weight: normal; margin-right: 4px;"><i class="fa-solid fa-tag"></i> ${res.keywords[i]}</span>`;
			}
			badgesKw += "</div>";
		}

		// Fragmentos de Contexto (Acordeón desplegable)
		let fuentesHtml = "";
		if (res.fuentes && res.fuentes.length > 0) {
			let collapseId = "colFuentes_" + new Date().getTime();
			fuentesHtml = `
				<div class="mt-2 pt-2 border-top">
					<a class="btn btn-sm btn-link text-decoration-none p-0" data-bs-toggle="collapse" href="#${collapseId}" role="button" aria-expanded="false" onclick="$('#${collapseId}').slideToggle();">
						<i class="fa-solid fa-folder-open"></i> Ver ${res.fuentes.length} p&aacute;rrafos encontrados (&plusmn;10 l&iacute;neas)
					</a>
					<div class="collapse mt-2" id="${collapseId}" style="display: none;">
						<div class="card card-body panel-body bg-light p-2" style="max-height: 180px; overflow-y: auto; font-size: 12px; background-color: #f1f3f9;">`;
			for (let j = 0; j < res.fuentes.length; j++) {
				let f = res.fuentes[j];
				fuentesHtml += `
					<div class="mb-2 p-1 border-bottom">
						<b>📄 ${f.archivo} (L&iacute;neas ${f.lineaInicio}-${f.lineaFin}):</b>
						<pre style="font-size: 11px; margin: 2px 0; white-space: pre-wrap; background: #fff; padding: 4px; border-radius: 4px;">${jsUtils.reemplazarSpecialToNormal(f.contenido)}</pre>
					</div>`;
			}
			fuentesHtml += `
						</div>
					</div>
				</div>`;
		}

		let respuestaFormateada = res.respuesta || "";
		let idContenidoRes = "resIA_" + new Date().getTime();

		let htmlBot = `
			<div class="d-flex justify-content-start mb-3 pull-left w-100" style="display: flex; justify-content: flex-start;">
				<div style="max-width: 85%; background-color: #ffffff; border: 1px solid #dddfeb; padding: 14px 18px; border-radius: 12px 12px 12px 0px; font-size: 14px;" class="shadow-sm">
					<b><i class="fa-solid fa-robot text-primary"></i> Asistente IA:</b>
					${badgesKw}
					<div id="${idContenidoRes}" class="mt-1"></div>
					${fuentesHtml}
				</div>
			</div>
			<div style="clear: both;"></div>`;

		$("#divChatBox").append(htmlBot);
		jsUtils.typewriterEffect.start(respuestaFormateada, idContenidoRes);
	}
};
