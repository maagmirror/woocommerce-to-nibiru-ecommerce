# WooCommerce Exporter to Nibiru Ecommerce

![Nibiru Logo](https://nibiru.com.uy/img/nibirulogo.svg)

## Descripción

Este plugin permite exportar productos y categorías de WooCommerce a la plataforma Nibiru eCommerce de manera sencilla y eficiente. La exportación se realiza en lotes (batches) para evitar problemas de tiempo de ejecución en sitios con muchos productos.

## Características principales

- ✅ Exportación por lotes de productos
- ✅ Soporte para productos variables y sus variantes
- ✅ Exportación automática de categorías
- ✅ Manejo de imágenes principales y galerías
- ✅ Opción para forzar stock mínimo
- ✅ Interfaz amigable con registro de actividad
- ✅ Mapeo inteligente de categorías (evita duplicados)

## Requisitos

- WordPress 5.0 o superior
- WooCommerce 4.0 o superior
- API Key de Nibiru eCommerce
- URL del endpoint de Nibiru eCommerce

## Instalación

1. Descarga el archivo ZIP del plugin
2. Ve a tu panel de WordPress > Plugins > Añadir nuevo > Subir plugin
3. Selecciona el archivo ZIP y haz clic en "Instalar ahora"
4. Activa el plugin después de la instalación

## Uso

1. Una vez instalado, encontrarás un nuevo menú "WC Exporter" en tu panel de administración de WordPress.
2. Ingresa tu API Key y la URL del API de Nibiru eCommerce.
3. Selecciona las opciones que necesites:
   - **Mostrar datos enviados**: Muestra los detalles técnicos de cada producto enviado
   - **Forzar stock mínimo**: Si un producto tiene stock 0, el plugin lo exportará con stock 1
4. Haz clic en "Exportar Productos" y el proceso comenzará automáticamente.
5. El panel de estado mostrará el progreso y cualquier mensaje de error en tiempo real.

## Datos que se exportan

### Productos simples:
- SKU
- Título/Nombre
- Precio
- Moneda
- Stock
- Tipo de producto (físico)
- Visibilidad
- Estado
- Imágenes (principal y galería)
- Categoría

### Productos variables:
- Todos los datos anteriores
- Variantes con:
  - Nombre/Valor de atributo
  - Precio específico de cada variante
  - Stock específico de cada variante
  - Color (si el atributo incluye "color" en su nombre)

### Categorías:
- ID de la categoría padre (jerarquía)
- Nombre
- Descripción

## Solución de problemas

- Si la exportación se detiene, verifica tu conexión a internet o los límites de tiempo de ejecución de tu servidor.
- Los errores específicos se mostrarán en el panel de estado durante la exportación.
- Si una categoría ya existe en Nibiru, el plugin la reutilizará en vez de crear un duplicado.

## Contribución

Las contribuciones son bienvenidas. Por favor, envía tus pull requests a nuestro repositorio.

## Licencia

Este plugin está licenciado bajo GPL v2 o posterior.

---

Desarrollado con ❤️ por el equipo de Nibiru
