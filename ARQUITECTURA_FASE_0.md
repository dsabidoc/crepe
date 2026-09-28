# CREPÉ — Arquitectura Fase 0

## Decisión de base

CREPÉ será un único monolito modular en **Laravel 13 + PHP 8.5 + MySQL 8**, con Blade y Livewire para las pantallas operativas. Administración, Recepción y Color Bar serán **modos de la misma sesión**, no aplicaciones ni bases de datos independientes. Un modo solo determina navegación, capacidades y contexto visual.

La unidad operativa es el **Ticket**. Una cita crea su ticket abierto; todas las áreas contribuyen a ese mismo registro. La base será una fuente de verdad transaccional: los saldos, existencias y comisiones se derivan de conceptos y movimientos inmutables, no de campos que se editen libremente.

### Consecuencias técnicas de las decisiones obligatorias

1. Las mutaciones críticas vivirán en servicios de dominio (`TicketService`, `InventoryService`, `PaymentService`, `CashService`, `CommissionService`), no en componentes Livewire ni controladores.
2. Pagos, anticipos, inventario, caja, ajustes y comisiones se **revierten** con un movimiento enlazado; no se actualizan ni eliminan de forma silenciosa.
3. Las operaciones de pago, consumo de fórmula, transferencia, venta y cierre de ticket usarán `DB::transaction()`, `lockForUpdate()` sobre las filas afectadas e idempotency keys para reintentos seguros. Un ticket llevará `lock_version` para advertir cambios concurrentes de su contenido no financiero.
4. El stock estará expresado siempre en la unidad base de la variante (g, ml, unidad, etc.). La UI puede mostrar “servicios aproximados”, pero nunca será la cantidad contable.
5. Los datos de catálogo se copiarán como snapshots en las líneas históricas al confirmar/cerrar una operación: nombres, precios, costos, reglas y porcentajes no cambiarán tickets pasados.

## Mapa de módulos

| Módulo | Responsabilidad |
| --- | --- |
| Identidad y acceso | Usuarios, empleados, roles, permisos, cambio de modo y auditoría de acceso. |
| CRM | Clientas, contacto, preferencias, notas, archivos y vista histórica unificada. |
| Agenda | Horarios, disponibilidad, bloqueos, citas, servicios previstos y ticket generado. |
| Ticket | Núcleo: servicios, productos, fórmulas, promociones, descuentos, pagos, saldo y timeline. |
| Catálogos | Servicios, productos, variantes, unidades, proveedores, métodos de pago, estados y reglas. |
| Inventario | Ubicaciones, existencias, compras, transferencias, consumos, ventas, mermas y kardex. |
| Color Bar | Cola de tickets elegibles, fórmulas, consumos exactos y cargos al ticket. |
| POS y caja | Anticipos, pagos divididos, recibos, cajas, sesiones, movimientos y cortes. |
| Comisiones | Reglas configurables, participantes y entradas congeladas al cierre. |
| Administración | KPIs, aprobaciones, reportes y configuración. |

## Modelo de datos y relaciones

### Identidad, personas y configuración

`users` se relaciona opcionalmente con `employees`; `roles`, `permissions`, `role_user` y `permission_role` permiten autorización granular en backend. `employees` contiene datos laborales y disponibilidad; `employee_schedules`, `employee_time_blocks` y `business_hours` modelan horarios, vacaciones, ausencias y bloqueos. Catálogos configurables: `appointment_statuses`, `ticket_statuses`, `payment_methods`, `units_of_measure` y `cash_registers`.

`customers` es la clienta. Tendrá `customer_notes`, `customer_tags`, `customer_files` y una vía extensible de `customer_attributes` para alergias, preferencias y futuro historial técnico, sin forzar columnas nuevas en cada mejora. Teléfono/WhatsApp normalizados y búsqueda indexada para prevenir duplicados.

### Agenda y ticket

`appointments` pertenece a una clienta, puede tener un ticket y conserva fecha, hora, duración y estado. `appointment_services` almacena servicios previstos, estilista prevista, precio estimado y snapshot del catálogo. La cita es la estimación; **nunca sustituye el total final del ticket**.

`tickets` pertenece a una clienta y opcionalmente a una cita. Contendrá folio único, estado, `estimated_total` calculado de sus líneas previstas y `lock_version`; su total final se calcula desde las líneas y ajustes vigentes. `ticket_items` será una tabla polimórfica de línea con `type` (`service`, `product`, `color_formula`, `fee`), cantidades, snapshots de nombre/unidad/precio/costo/impuestos y estado (`active`, `voided`). Las extensiones por tipo serán `ticket_service_details` y `ticket_product_details`; `ticket_service_participants` permite repartir un servicio entre varias estilistas con porcentajes explícitos.

`ticket_adjustments` registra descuento, promoción, ajuste manual o reverso, con monto, base de cálculo, motivo, autorización y vínculo al elemento afectado cuando aplique. `ticket_notes` y `ticket_activities` construyen el historial humano. Una línea cancelada se marca como anulada y conserva quién, cuándo y por qué; si ya tuvo efecto financiero o de inventario, además se genera su reverso correspondiente.

### Productos e inventario

`product_categories`, `brands`, `products`, `product_variants`, `variant_option_types` y `variant_option_values` cubren productos y variantes dinámicas. Cada variante define unidad base, contenido comercial, costo/precio vigentes, umbral mínimo y si puede participar en fórmula. `color_pricing_rules` permite reglas escalonadas por cantidad sin acoplarlas a gramos.

`inventory_locations` no tendrá tipos rígidos: inicialmente se sembrarán Almacén, Color Bar y Recepción, pero una ubicación puede relacionarse después con sucursal/área. `inventory_balances` es una proyección por variante-ubicación con `available_quantity`; `inventory_movements` es el kardex inmutable, con tipo, cantidad firmada, origen/destino, costo unitario snapshot, referencia polimórfica, usuario, motivo y enlace a un movimiento reverso. Para una transferencia se crean dos movimientos correlacionados dentro de la misma transacción. Las compras se registran en `purchase_entries` y `purchase_entry_items`; sus entradas de inventario mantienen trazabilidad de costo.

### Fórmulas, pagos, caja y comisiones

`color_formulas` pertenece a ticket y clienta; `color_formula_items` conserva variante, cantidad exacta, unidad, costo/precio snapshots y el movimiento de inventario asociado. Confirmar una fórmula crea sus movimientos desde la ubicación seleccionada (por defecto, la de Color Bar) y sus líneas de ticket en una sola transacción.

`payments` representa cada abono, pago o devolución; `payment_allocations` permite asignar pagos divididos a conceptos cuando después sea necesario. Un pago cancelado se preserva y se compensa por otro pago/reverso enlazado. `cash_sessions` abre/cierra una caja; `cash_movements` registra fondo, pagos en efectivo, retiros, ingresos y reversos. `cash_closures` y `cash_closure_events` implementan borrador, envío, autorización/rechazo y motivo obligatorio.

`commission_rules` configura servicio, producto o rango mensual; `commission_entries` guarda la comisión resultante, sus snapshots, participante y estado. La regla elegida y el modo de rango (retroactivo o marginal) quedan congelados al cerrar el ticket.

`audit_logs` será append-only y conservará actor, acción, entidad, before/after JSON, motivo, IP, request/correlation id y fecha. Es adicional al historial operativo: la activity es legible; la auditoría es forense.

## Estados principales

| Entidad | Estados y transiciones permitidas |
| --- | --- |
| Cita | Programada → Confirmada → Llegó → En servicio → Lista para cobrar → Pagada; Programada/Confirmada → Cancelada o No asistió. |
| Ticket | Abierto → En servicio → Listo para cobrar → Pagado; Abierto/En servicio → Cancelado. El estado se valida contra la cita sin obligar a que ambos estén siempre idénticos. |
| Línea de ticket | Activa → Anulada. Una línea facturada/pagada exige reverso, no eliminación. |
| Fórmula | Borrador → Confirmada → Revertida. |
| Pago | Registrado → Aplicado; Registrado/Aplicado → Revertido. |
| Sesión de caja | Abierta → En corte → Cerrada. |
| Corte | Borrador → Enviado → Autorizado o Rechazado → Borrador corregido. |
| Inventario | Los movimientos son finales; un error genera un movimiento de reverso/ajuste enlazado. |

Los catálogos de estados permitirán etiqueta, color, orden, permisos de transición y si el estado bloquea edición; las transiciones permitidas quedarán definidas en una máquina de estados de dominio, no libres por UI.

## Roles y permisos iniciales

Roles sugeridos: Administrador, Gerencia, Administración, Recepción, Color Bar y Estilista. Los roles son conjuntos iniciales, no la decisión final de acceso. Permisos por acción cubrirán `appointments.*`, `customers.*`, `tickets.view/update/price/discount/charge/cancel`, `inventory.view/move/adjust/cost`, `color-formulas.*`, `cash.open/close/authorize`, `commissions.*`, `reports.view` y `settings.*`.

El selector de modo muestra únicamente los modos con al menos un permiso operativo. Cambiar de modo no cierra sesión ni reconfigura datos; solo actualiza el contexto de la interfaz. Toda acción sensible se autoriza nuevamente en Policy/Gate y en el servicio de dominio.

## Navegación

**Selector de modo**: Administración, Recepción y Color Bar según permisos.

**Administración**: Dashboard · Agenda · Tickets · Clientas · Equipo · Servicios · Productos · Inventario · Compras · Comisiones · Caja y cortes · Promociones · Reportes · Configuración.

**Recepción**: Inicio del día · Agenda · Tickets · Clientas · Caja. El ticket se abre como drawer desde Agenda, búsqueda o listado; los formularios de configuración/edición completa abren página.

**Color Bar**: Tickets activos · Productos · Inventario Color Bar · Historial de fórmulas. Su ticket es una vista operacional limitada; nunca una copia del ticket.

## Sistema visual extraído del Figma

El archivo contiene un POS de referencia con login, tablero POS, lista/detalle lateral de órdenes, cobro, confirmaciones, facturas, gestión de productos y perfil. La traducción para CREPÉ conservará el lenguaje observado: canvas blanco, navegación superior ligera, tipografía sans compacta, azul intenso reservado para acciones/selección, superficies gris muy claras, tarjetas blancas con radio suave, bordes finos y sombras apenas perceptibles.

Componentes reutilizables a construir en la Fase 1: `AppShell`, `TopNav`, `ModeSwitcher`, `PageHeader`, `SearchField`, `SegmentedTabs`, `StatusPill`, `MetricCard`, `TicketCard`, `DataTable`, `ActionMenu`, `PrimaryButton`, `SecondaryButton`, `FormField`, `ConfirmModal`, `RightDrawer`, `TicketDrawer`, `PaymentDrawer`, `FormulaCart`, `Toast` y estados empty/loading/error. El drawer contextual y el modal de éxito de pago serán patrones centrales, en lugar de navegar innecesariamente fuera del flujo.

Tokens iniciales a confirmar al implementar: escala de espacios de 4 px, radius suave (8–12 px), tipografía sans de 12–28 px, alturas táctiles mínimas de 44 px, azul primario de la referencia, gris de borde muy tenue y sombra de baja opacidad. Se extraerán valores exactos al preparar los componentes desde el archivo de Figma abierto.

## Riesgos, ambigüedades y decisiones pendientes

1. **Impuestos, moneda y facturación:** faltan régimen fiscal, IVA aplicable y reglas de redondeo. Se modelarán como configuración por línea, pero su comportamiento fiscal debe confirmarse antes de cobrar producción.
2. **Transferencias de inventario:** conviene definir si requieren aceptación de destino. Propongo soportar `requested/in_transit/received` para futuro, con transferencia inmediata inicial configurable.
3. **Reserva de stock:** el consumo de Color Bar se descontará al confirmar fórmula, no al abrirla. Productos de venta se descontarán al cobrar; esto evita reservas abandonadas. Si se requiere apartar inventario, se añadirá explícitamente una capa de reservas con caducidad.
4. **Precio de Color Bar:** debe decidirse si sus cargos se muestran como líneas independientes, se absorben en el servicio o ambos. Propongo líneas visibles independientes con una regla que permita marcarlas “incluidas” sin perder costo.
5. **Comisiones de producto:** los rangos requieren acordar si se calculan sobre unidades, importe neto o importe antes de descuentos, y si el rango es retroactivo o marginal. El esquema soportará ambas opciones, pero la regla inicial necesita definición.
6. **Cierres históricos:** si un ticket ya está pagado, cualquier devolución debe quedar vinculada a una sesión de caja abierta y a su pago original; no se permitirá cambiar importes desde la pantalla de ticket.

## Entrega por fases

Fase 1 implementará el shell visual, autenticación, usuario de prueba solicitado, selector de modo y permisos. Después seguirán los módulos en el orden del brief: catálogos y CRM, agenda, ticket, inventario, Color Bar, POS/caja, comisiones y reportes. Cada fase incluirá migraciones, pruebas de autorización/transacciones y seeders realistas.
