# ReyGom API v1.1.0 — módulo Properties

Este paquete reemplaza únicamente:

- `src/Models/Property.php`
- `src/Routes/properties.php`

## 1. Copia de seguridad

```bash
cd ~/reygom-api
cp src/Models/Property.php src/Models/Property.php.pre-v11.bak
cp src/Routes/properties.php src/Routes/properties.php.pre-v11.bak
```

## 2. Copiar archivos

Copia los dos archivos del paquete respetando sus rutas.

## 3. Validar sintaxis

Si PHP está instalado en el host:

```bash
./validate.sh
```

Si PHP solo está disponible en Kubernetes:

```bash
POD=$(kubectl -n reygom get pod -l app=reygom-api -o jsonpath='{.items[0].metadata.name}')
kubectl cp src/Models/Property.php "reygom/$POD:/tmp/Property.php"
kubectl cp src/Routes/properties.php "reygom/$POD:/tmp/properties.php"
kubectl -n reygom exec "$POD" -- sh -c 'php -l /tmp/Property.php && php -l /tmp/properties.php'
```

## 4. Construcción y despliegue

El script `deploy-v10.sh` usa estos valores predeterminados:

- namespace: `reygom`
- deployment: `reygom-api`
- container: `reygom-api`
- image: `reygom-api:v10`

Ejemplo:

```bash
IMAGE=tu-registro/reygom-api:v10 ./deploy-v10.sh
```

> Importante: si MicroK8s usa su propio registro o runtime, construye/publica la imagen siguiendo el flujo que ya utiliza tu servidor y después ejecuta `kubectl set image`.

## 5. Pruebas mínimas

- Administrador: listar todos los inmuebles.
- Agente: listar únicamente los propios.
- Agente: intentar leer/modificar/eliminar un inmueble ajeno debe devolver 404.
- Crear inmueble sin `reference_id`: debe generar la referencia usando ciudad y provincia.
- Referencia duplicada: debe devolver 409.
- `agent_id` inexistente: debe devolver 400.

## Nota de integración

La ruta espera que:

- `authenticate()` devuelva un objeto con `id` y `role`.
- exista `PropertyReferenceGenerator` con el método `generate(city, province, countryCode)`.
- la tabla `properties` tenga los campos utilizados en el modelo.
