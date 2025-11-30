# Plantilla de Certificado de Convenio

## Ubicación
Coloca tu plantilla Word aquí: `resources/templates/certificado_convenio_template.docx`

**Nota:** Esta carpeta está bajo control de versiones, por lo que la plantilla se incluirá en el repositorio.

## Placeholders Disponibles

En tu plantilla Word, usa los siguientes placeholders entre `${}` para que sean reemplazados automáticamente:

### Datos del Afiliado
- `${NOMBRE_COMPLETO}` - Nombre completo en mayúsculas (ej: "RESTREPO GARCES JUAN ESTEBAN")
- `${DOCUMENTO}` - Número de documento formateado con puntos (ej: "1.036.965.209")
- `${FECHA_INGRESO}` - Fecha de ingreso formateada (ej: "19 de abril de 2024")
- `${FECHA_RETIRO}` - Fecha de retiro formateada con prefijo (ej: ", hasta 26 de noviembre de 2025") o vacío si no aplica
- `${CORREO_PERSONAL}` - Correo electrónico personal del afiliado
- `${SEXO}` - Sexo del afiliado (en mayúsculas: "M", "F", etc.)
- `${ESTADO}` - Estado del afiliado (en mayúsculas: "ACTIVO", "INACTIVO", etc.)

### Placeholders Procesados (Condicionales)
Estos placeholders ya vienen procesados según el valor de `${SEXO}`, `${ESTADO}` y otros campos:

**Condicionales basados en SEXO:**
- `${TITULO_PERSONA}` - "el señor" o "la señora" (según el sexo)
- `${IDENTIFICADO_IDENTIFICADA}` - "identificado" o "identificada" (según el sexo)
- `${AFILIADO_AFILIADA}` - "afiliado" o "afiliada" (según el sexo)
- `${DEL_INTERESADO_DE_LA_INTERESADA}` - "del interesado" o "de la interesada" (según el sexo)

**Condicionales basados en ESTADO:**
- `${ATIENDE_ATENDIO}` - "atiende" o "atendió" (según si el afiliado está activo)
- `${SE_ENCUENTRA_ESTUVO}` - "se encuentra" o "estuvo" (según si el afiliado está activo)
- `${DESARROLLA_ACTUALMENTE_DESARROLLO}` - "desarrolla actualmente" o "desarrolló" (según si el afiliado está activo)

**Condicionales basados en CANTIDAD:**
- `${UN_CONVENIO_VARIOS_CONVENIOS}` - "un Convenio" o "varios Convenios" (según la cantidad de convenios del afiliado)

**Otros:**
- `${DESTINATARIO_COMPLETO}` - "A quien corresponda." o "Señores [nombre]" (según si hay destinatario)

### Datos del Convenio
- `${HOSPITAL}` - Hospital/Entidad donde trabaja (transformado del campo Cliente del Excel a formato legible, ej: "E.S.E. Hospital San Juan de Dios - Rionegro (Ant)")
- `${PROCESO}` - Proceso/Cargo en mayúsculas (ej: "CAMILLERO(A)")
- `${LISTA_CONVENIOS}` - Lista formateada de todos los convenios del afiliado con hospitales transformados (ver formato abajo)

### Datos del Certificado
- `${FECHA_CERTIFICADO}` - Fecha completa de emisión (ej: "26 de noviembre de 2025")
- `${DIA_CERTIFICADO}` - Solo el día (ej: "26")
- `${MES_CERTIFICADO}` - Solo el mes en español (ej: "noviembre")
- `${ANIO_CERTIFICADO}` - Solo el año (ej: "2025")
- `${CONSECUTIVO}` - Número consecutivo del certificado (ej: "202511261234")

## Ejemplo de Uso en Word

En tu plantilla Word, puedes usar placeholders así:

```
${DESTINATARIO_COMPLETO}

El Sindicato de Profesionales de la Salud (ProSalud) RUT. 900.444.737-1, 
certifica que ${TITULO_PERSONA} ${NOMBRE_COMPLETO}, ${IDENTIFICADO_IDENTIFICADA} 
con documento de identidad ${DOCUMENTO}, se encuentra ${AFILIADO_AFILIADA} 
al Sindicato desde ${FECHA_INGRESO}${FECHA_RETIRO}, a través de un Convenio 
de Ejecución Sindical, que ${DESARROLLA_ACTUALMENTE_DESARROLLO} en ${HOSPITAL}.

En calidad de ${AFILIADO_AFILIADA} ${ATIENDE_ATENDIO} procesos de ${PROCESO}.

Este certificado de convenio se expide por solicitud del interesado a los 
${DIA_CERTIFICADO} días del mes de ${MES_CERTIFICADO} de ${ANIO_CERTIFICADO}.

CONVENIOS

Ha participado en los siguientes convenios:

${LISTA_CONVENIOS}
```

### Formato de LISTA_CONVENIOS

El placeholder `${LISTA_CONVENIOS}` genera una lista de todos los convenios del afiliado, 
ordenados cronológicamente (del más antiguo al más reciente). Cada convenio se muestra en 
una línea con el siguiente formato:

```
❖ [Cliente] , desde [mes] [día]/[año] hasta [mes] [día]/[año]
```

Si el convenio está vigente (activo sin fecha de fin), se muestra:
```
❖ [Cliente] , desde [mes] [día]/[año] hasta la fecha, se encuentra vigente
```

**Ejemplo de salida:**
```
❖ E.S.E. Hospital Marco Fidel Suárez de Bello , desde ene. 01/2019 hasta dic. 31/2023
❖ E.S.E. Hospital Marco Fidel Suárez de Bello, desde ene. 01/2024 hasta la fecha, se encuentra vigente
```

## Nota sobre Campos Condicionales

**IMPORTANTE:** PHPWord no procesa campos IF de Word (como `{IF ...}`). 
Por esta razón, los condicionales se procesan en PHP y se envían como placeholders 
ya resueltos. Usa los placeholders procesados (`${TITULO_PERSONA}`, 
`${IDENTIFICADO_IDENTIFICADA}`, etc.) en lugar de campos IF en tu plantilla.

## Notas Importantes

1. Los placeholders son **case-sensitive** (sensibles a mayúsculas/minúsculas)
2. Asegúrate de usar exactamente el formato `${NOMBRE_DEL_PLACEHOLDER}`
3. Si un placeholder no tiene valor, se reemplazará con una cadena vacía
4. La plantilla debe estar en formato `.docx` (Word 2007 o superior)
5. La plantilla está en `resources/templates/` para estar bajo control de versiones

