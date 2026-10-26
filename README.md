# Plugin B2B – Listas de precios B2B para WooCommerce / B2B Price Lists for WooCommerce

[Español](#español) · [English](#english)

---

## Español

Plugin de WordPress que permite crear **listas de precios B2B centralizadas** y asignarlas directamente a cada cliente, sin depender de roles de usuario. Quien no tiene lista asignada sigue viendo la tienda normal (B2C).

**Versión:** 3.7.0

### Qué hace

- Listas de precios con reglas por producto, por variación o para todas las variaciones de un producto.
- Precios por rangos de cantidad, mínimos de compra y ofertas por porcentaje (por lista, regla o rango).
- Precios resueltos en el servidor en catálogo, ficha de producto, carrito, checkout y Store API / bloques.
- Identificación y filtrado de pedidos B2B en *WooCommerce → Pedidos* (compatible con HPOS) y resumen B2B en el detalle del pedido.
- Historial de actividad con retención configurable y exportación a CSV.
- Webhook privado (`/wp-json/b2bpl/v1/webhook`) para recibir listas, precios, clientes y productos desde un sistema externo (ERP), con credenciales propias, registro de cada envío y avisos por correo de los errores.
- Panel de administración con guía de uso integrada.

### Requisitos

- WordPress 7.0.2 o superior
- WooCommerce 10.9.4 o superior
- PHP 8.0.30 o superior

### Instalación

1. En esta página, abre el botón verde **Code** y elige **Download ZIP**.
2. En WordPress, ve a *Plugins → Añadir nuevo → Subir plugin*, elige el ZIP y pulsa **Instalar ahora**.
3. Activa el plugin.
4. Crea tus listas en el menú *Listas de precios B2B* y asígnalas a cada cliente desde su perfil.

### Desarrollo

Desarrollado por **Fer Santizo** · [github.com/Fer1605](https://github.com/Fer1605)

### Licencia

GPL-2.0-or-later

---

## English

WordPress plugin to create **centralized B2B price lists** and assign them directly to each customer, independent of user roles. Customers without a list keep the regular (B2C) store experience.

**Version:** 3.7.0

### Features

- Price lists with per-product, per-variation or all-variations rules.
- Quantity tiers, minimum quantities and percentage offers (per list, rule or tier).
- Prices resolved on the server in the catalog, product page, cart, checkout and the Store API / blocks.
- B2B orders identified and filterable in *WooCommerce → Orders* (HPOS compatible), with a B2B summary on the order screen.
- Activity history with configurable retention and CSV export.
- Private webhook (`/wp-json/b2bpl/v1/webhook`) to receive lists, prices, customers and products from an external system (ERP), with its own credentials, a log of every request and email alerts on errors.
- Admin panel with a built-in user guide.

### Requirements

- WordPress 7.0.2 or later
- WooCommerce 10.9.4 or later
- PHP 8.0.30 or later

### Installation

1. On this page, open the green **Code** button and choose **Download ZIP**.
2. In WordPress, go to *Plugins → Add New → Upload Plugin*, choose the ZIP and click **Install Now**.
3. Activate the plugin.
4. Create your lists in the *B2B Price Lists* menu and assign them to each customer from their profile.

### Author

Developed by **Fer Santizo** · [github.com/Fer1605](https://github.com/Fer1605)

### License

GPL-2.0-or-later
