package sa.ia.logic;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Lógica de búsqueda por palabras clave y sinónimos (Grep RAG Ligero)
 * sobre la base de conocimiento en formato Markdown (.md).
 * 
 * Calcula puntuación por relevancia TF-IDF, densidad de términos,
 * coincidencias en títulos y cobertura de consulta.
 */
public class ChatbotGrepLogic {
	public boolean showSQLS = false;

	/**
	 * Realiza la búsqueda de un conjunto de palabras/sinónimos con parámetros avanzados
	 * de ranking, filtrado y tamaño de contexto.
	 * 
	 * @param folderPath    Ruta física de la carpeta con archivos .md.
	 * @param keywords      Array JSON con palabras clave y sinónimos.
	 * @param margenLineas  Líneas antes y después de cada coincidencia (default: 6).
	 * @param topK          Cantidad máxima de mejores fragmentos a devolver (default: 5, <=0 para todos).
	 * @param minScore      Puntaje mínimo de relevancia para incluir el bloque (default: 0.0).
	 * @return JSONArray con los bloques más relevantes ordenados descendentemente por score.
	 */
	public JSONArray buscarContexto(String folderPath, JSONArray keywords, int margenLineas, int topK, double minScore) {
		JSONArray resultado = new JSONArray();
		if (keywords == null || keywords.length() == 0) {
			return resultado;
		}

		File folder = new File(folderPath);
		if (!folder.exists() || !folder.isDirectory()) {
			return resultado;
		}

		File[] mdFiles = folder.listFiles((dir, name) -> name.toLowerCase().endsWith(".md"));
		if (mdFiles == null || mdFiles.length == 0) {
			return resultado;
		}

		// Convertir las palabras clave a minúsculas y de-duplicar
		List<String> kwList = new ArrayList<>();
		for (int i = 0; i < keywords.length(); i++) {
			String kw = keywords.optString(i, "").trim().toLowerCase();
			if (!kw.isEmpty() && !kwList.contains(kw)) {
				kwList.add(kw);
			}
		}

		if (kwList.isEmpty()) {
			return resultado;
		}

		int margen = (margenLineas > 0) ? margenLineas : 6;
		int totalDocs = mdFiles.length;

		// 1. Pre-cálculo de Document Frequency (DF) para cada keyword (para IDF)
		Map<String, Integer> docFrequency = new HashMap<>();
		for (String kw : kwList) {
			docFrequency.put(kw, 0);
		}

		Map<File, List<String>> cacheArchivos = new HashMap<>();
		for (File file : mdFiles) {
			List<String> lineas = leerLineasArchivo(file);
			cacheArchivos.put(file, lineas);

			String fileLower = file.getName().toLowerCase();
			String fileContentLower = String.join(" ", lineas).toLowerCase();

			for (String kw : kwList) {
				if (fileLower.contains(kw) || fileContentLower.contains(kw)) {
					docFrequency.put(kw, docFrequency.get(kw) + 1);
				}
			}
		}

		// Clase auxiliar interna para ranking
		class ChunkCandidato {
			String archivo;
			int lineaInicio;
			int lineaFin;
			String contenido;
			double score;
			int kwCoincidentes;
		}

		List<ChunkCandidato> candidatos = new ArrayList<>();
		Set<String> bloquesUnicos = new HashSet<>();

		// 2. Extracción y Scoring de fragmentos
		for (File file : mdFiles) {
			List<String> lineas = cacheArchivos.get(file);
			if (lineas == null || lineas.isEmpty()) {
				continue;
			}

			int totalLineas = lineas.size();
			Set<Integer> lineasCoincidentes = new HashSet<>();
			String fileNameLower = file.getName().toLowerCase();

			// Detectar líneas con coincidencias
			for (int i = 0; i < totalLineas; i++) {
				String lineaLower = lineas.get(i).toLowerCase();
				for (String kw : kwList) {
					if (lineaLower.contains(kw)) {
						lineasCoincidentes.add(i);
						break;
					}
				}
			}

			if (lineasCoincidentes.isEmpty()) {
				continue;
			}

			List<int[]> rangos = obtenerRangosFusionados(lineasCoincidentes, totalLineas, margen);

			for (int[] rango : rangos) {
				int inicio = rango[0];
				int fin = rango[1];

				StringBuilder sb = new StringBuilder();
				for (int i = inicio; i <= fin; i++) {
					sb.append(lineas.get(i)).append("\n");
				}

				String bloqueTexto = sb.toString().trim();
				if (bloqueTexto.isEmpty() || bloquesUnicos.contains(bloqueTexto)) {
					continue;
				}
				bloquesUnicos.add(bloqueTexto);

				String bloqueLower = bloqueTexto.toLowerCase();
				double score = 0.0;
				int distinctKwCount = 0;

				for (String kw : kwList) {
					int df = docFrequency.getOrDefault(kw, 1);
					if (df == 0) df = 1;
					double idf = Math.log(1.0 + ((double) totalDocs / (double) df));

					int countInBlock = 0;
					int idx = 0;
					while ((idx = bloqueLower.indexOf(kw, idx)) != -1) {
						countInBlock++;
						idx += kw.length();
					}

					if (countInBlock > 0) {
						distinctKwCount++;
						score += (idf * countInBlock * 2.0);
					}

					// Bonus por coincidencia en el nombre del archivo / tema
					if (fileNameLower.contains(kw)) {
						score += (idf * 8.0);
					}
				}

				// Bonus por cobertura conjunta (múltiples keywords distintas en el mismo bloque)
				if (distinctKwCount > 1) {
					score += Math.pow(distinctKwCount, 2) * 5.0;
				}

				if (score >= minScore) {
					ChunkCandidato c = new ChunkCandidato();
					c.archivo = file.getName();
					c.lineaInicio = inicio + 1;
					c.lineaFin = fin + 1;
					c.contenido = bloqueTexto;
					c.score = Math.round(score * 100.0) / 100.0;
					c.kwCoincidentes = distinctKwCount;
					candidatos.add(c);
				}
			}
		}

		// 3. Ordenar descendentemente por score
		candidatos.sort((a, b) -> Double.compare(b.score, a.score));

		// 4. Aplicar límite Top-K
		int limite = (topK > 0) ? Math.min(topK, candidatos.size()) : candidatos.size();
		for (int i = 0; i < limite; i++) {
			ChunkCandidato c = candidatos.get(i);
			JSONObject chunk = new JSONObject();
			chunk.put("archivo", c.archivo);
			chunk.put("lineaInicio", c.lineaInicio);
			chunk.put("lineaFin", c.lineaFin);
			chunk.put("contenido", c.contenido);
			chunk.put("score", c.score);
			chunk.put("kwCount", c.kwCoincidentes);
			resultado.put(chunk);
		}

		if (showSQLS) {
			System.out.println("[ChatbotGrepLogic - Scoring]: Evaluados " + candidatos.size() + " bloques. Retornando Top " + resultado.length() + " (margen=" + margen + ", topK=" + topK + ", minScore=" + minScore + ").");
		}

		return resultado;
	}

	/**
	 * Lee de manera segura todas las líneas de un archivo usando UTF-8.
	 */
	private List<String> leerLineasArchivo(File file) {
		List<String> lineas = new ArrayList<>();
		try (BufferedReader br = new BufferedReader(new InputStreamReader(new FileInputStream(file), StandardCharsets.UTF_8))) {
			String linea;
			while ((linea = br.readLine()) != null) {
				lineas.add(linea);
			}
		} catch (Exception e) {
			e.printStackTrace();
		}
		return lineas;
	}

	/**
	 * Dada una serie de líneas con coincidencia, genera rangos [inicio, fin]
	 * expandiendo ±margen líneas y fusionando aquellos que se solapen.
	 */
	private List<int[]> obtenerRangosFusionados(Set<Integer> lineasCoincidentes, int totalLineas, int margen) {
		List<int[]> rangosRaw = new ArrayList<>();
		for (int idx : lineasCoincidentes) {
			int inicio = Math.max(0, idx - margen);
			int fin = Math.min(totalLineas - 1, idx + margen);
			rangosRaw.add(new int[]{inicio, fin});
		}

		// Ordenar por inicio
		rangosRaw.sort((a, b) -> Integer.compare(a[0], b[0]));

		List<int[]> fusionados = new ArrayList<>();
		if (rangosRaw.isEmpty()) {
			return fusionados;
		}

		int[] actual = rangosRaw.get(0);
		for (int i = 1; i < rangosRaw.size(); i++) {
			int[] siguiente = rangosRaw.get(i);
			if (siguiente[0] <= actual[1] + 1) {
				// Solapamiento o contiguos, fusionar
				actual[1] = Math.max(actual[1], siguiente[1]);
			} else {
				fusionados.add(actual);
				actual = siguiente;
			}
		}
		fusionados.add(actual);

		return fusionados;
	}
}
