# User API Endpoints

Esta documentación describe todos los endpoints disponibles para la gestión de usuarios.

## Base URL
```
/api/users
```

## Endpoints Disponibles

### 1. Listar Usuarios
**GET** `/api/users`

**Parámetros de consulta:**
- `search` (opcional): Buscar por nombre o email
- `is_active` (opcional): Filtrar por estado activo (true/false)
- `per_page` (opcional): Número de elementos por página (default: 15)

**Ejemplo de petición:**
```bash
GET /api/users?search=Juan&is_active=true&per_page=10
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Juan Pérez",
      "email": "juan@example.com",
      "is_active": true,
      "created_at": "2025-09-14T22:27:08.000000Z",
      "updated_at": "2025-09-14T22:27:08.000000Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1,
    "from": 1,
    "to": 1
  }
}
```

### 2. Crear Usuario
**POST** `/api/users`

**Cuerpo de la petición:**
```json
{
  "name": "Juan Pérez",
  "email": "juan@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "is_active": true
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Usuario creado exitosamente",
  "data": {
    "id": 1,
    "name": "Juan Pérez",
    "email": "juan@example.com",
    "is_active": true,
    "created_at": "2025-09-14T22:27:08.000000Z"
  }
}
```

### 3. Obtener Usuario Específico
**GET** `/api/users/{id}`

**Respuesta exitosa:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Juan Pérez",
    "email": "juan@example.com",
    "is_active": true,
    "created_at": "2025-09-14T22:27:08.000000Z",
    "updated_at": "2025-09-14T22:27:08.000000Z"
  }
}
```

### 4. Actualizar Usuario
**PUT/PATCH** `/api/users/{id}`

**Cuerpo de la petición (todos los campos son opcionales):**
```json
{
  "name": "Juan Carlos Pérez",
  "email": "juan.carlos@example.com",
  "password": "newpassword123",
  "password_confirmation": "newpassword123",
  "is_active": false
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Usuario actualizado exitosamente",
  "data": {
    "id": 1,
    "name": "Juan Carlos Pérez",
    "email": "juan.carlos@example.com",
    "is_active": false,
    "updated_at": "2025-09-14T22:30:15.000000Z"
  }
}
```

### 5. Cambiar Estado del Usuario
**PATCH** `/api/users/{id}/status`

**Cuerpo de la petición:**
```json
{
  "is_active": false
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Usuario desactivado exitosamente",
  "data": {
    "id": 1,
    "name": "Juan Pérez",
    "email": "juan@example.com",
    "is_active": false,
    "updated_at": "2025-09-14T22:30:15.000000Z"
  }
}
```

### 6. Eliminar Usuario
**DELETE** `/api/users/{id}`

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Usuario eliminado exitosamente"
}
```

## Códigos de Estado HTTP

- `200` - OK (operación exitosa)
- `201` - Created (usuario creado exitosamente)
- `422` - Unprocessable Entity (errores de validación)
- `404` - Not Found (usuario no encontrado)
- `500` - Internal Server Error (error del servidor)

## Ejemplos de Errores de Validación

### Error al crear usuario con email duplicado:
```json
{
  "success": false,
  "message": "Errores de validación",
  "errors": {
    "email": [
      "Este correo electrónico ya está registrado."
    ]
  }
}
```

### Error al actualizar con contraseña muy corta:
```json
{
  "success": false,
  "message": "Errores de validación",
  "errors": {
    "password": [
      "La contraseña debe tener al menos 8 caracteres."
    ]
  }
}
```

## Notas Importantes

1. **Contraseñas**: Se almacenan hasheadas automáticamente
2. **Email único**: No se pueden crear usuarios con emails duplicados
3. **Validación**: Todos los campos son validados según las reglas definidas
4. **Logging**: Todas las operaciones se registran en los logs
5. **Paginación**: La lista de usuarios incluye paginación automática
6. **Búsqueda**: Se puede buscar por nombre o email
7. **Filtros**: Se puede filtrar por estado activo/inactivo

