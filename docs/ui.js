// Visor de la especificacion OpenAPI. "Try it out" desactivado: la documentacion es de solo lectura.
window.addEventListener('load', function () {
  window.ui = SwaggerUIBundle({
    url: 'openapi.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    docExpansion: 'list',
    defaultModelsExpandDepth: 0,
    supportedSubmitMethods: [],
    tagsSorter: 'alpha'
  });
});
