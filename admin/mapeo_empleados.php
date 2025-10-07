<?php
/**
 * Mapeo de empleados para procesamiento automático de PDFs SUA
 * Contiene NSS, nombres completos y CURP para identificación automática
 */

class MapeoEmpleados {
      // Base de datos de empleados conocidos
    private static $empleados_conocidos = [
        '62-84-67-2655-2' => [
            'nss' => '62-84-67-2655-2',
            'nombre' => 'COYOTL REYES JOSE REMEDIOS',
            'curp' => 'CORR670901HPLYYM03'
        ],
        '61-05-84-0852-9' => [
            'nss' => '61-05-84-0852-9',
            'nombre' => 'CUAYA ADRIAN VICTOR',
            'curp' => 'CUAV841116HPLYDC09'
        ],
        '03-18-87-3482-0' => [
            'nss' => '03-18-87-3482-0',
            'nombre' => 'CUAYA CUAYA MIGUEL',
            'curp' => 'CUCM870928HPLYYG04'
        ],
        '48-06-79-0418-4' => [
            'nss' => '48-06-79-0418-4',
            'nombre' => 'GALEOTI JUAREZ FRANCISCO JAVIER',
            'curp' => 'GAJF790304HPLLRR04'
        ],
        '48-07-88-2625-1' => [
            'nss' => '48-07-88-2625-1',
            'nombre' => 'GOMEZ ACOSTA LUIS ALBERTO',
            'curp' => 'GOAL880125HMCMCS03'
        ],
        '48-06-86-1596-1' => [
            'nss' => '48-06-86-1596-1',
            'nombre' => 'LOPEZ CUAYA JOSE ALBERTO',
            'curp' => 'LXCA861121HPLPYL03'
        ],
        '61-96-78-0855-3' => [
            'nss' => '61-96-78-0855-3',
            'nombre' => 'PIÑA BRIONES ROGELIO',
            'curp' => 'PIBR780713HTLXRG02'
        ],
        '03-23-01-3168-6' => [
            'nss' => '03-23-01-3168-6',
            'nombre' => 'RAMIREZ DIEGO ANGEL',
            'curp' => 'RADA010921HPLMGNA0'
        ],
        '08-18-00-2741-2' => [
            'nss' => '08-18-00-2741-2',
            'nombre' => 'SALVADOR DAZA ALEJANDRO',
            'curp' => 'SADA000229HPLLZLA1'
        ],
        '57-15-95-9467-0' => [
            'nss' => '57-15-95-9467-0',
            'nombre' => 'TAPIA SANCHEZ JOSE ALEJANDRO',
            'curp' => 'TASA951016HPLPNL00'
        ],
        '04-17-00-8938-9' => [
            'nss' => '04-17-00-8938-9',
            'nombre' => 'TZOMPA LOZADA VICTOR MANUEL',
            'curp' => 'TOLV000116HPLZZCA0'
        ],
        // Empleados adicionales del mapeo completo
        '61-17-80-1243-0' => [
            'nss' => '61-17-80-1243-0',
            'nombre' => 'ALCANTARA GOMEZ TEODORO',
            'curp' => 'AAGT800306HPLCMD03'
        ],
        '03-04-75-4632-0' => [
            'nss' => '03-04-75-4632-0',
            'nombre' => 'BLANCO CERVANTES LORENZO',
            'curp' => 'BACL750418HPLLRR08'
        ],
        '03-97-92-0893-9' => [
            'nss' => '03-97-92-0893-9',
            'nombre' => 'CALVA GARCIA JUAN CARLOS',
            'curp' => 'CAGJ920323HPLLRN08'
        ],
        '03-19-78-2624-4' => [
            'nss' => '03-19-78-2624-4',
            'nombre' => 'CHINCHILLA AGUILAR FERNANDO',
            'curp' => 'CIAF780101HPLHGR06'
        ],
        '04-08-82-4519-9' => [
            'nss' => '04-08-82-4519-9',
            'nombre' => 'CORTES HERNANDEZ SERGIO MIGUEL',
            'curp' => 'COHS820216HPLRRG01'
        ],
        '48-96-75-9102-7' => [
            'nss' => '48-96-75-9102-7',
            'nombre' => 'COYOTL CARLOS CARLOS',
            'curp' => 'COCC751105HPLYYR08'
        ],
        '03-18-86-7431-9' => [
            'nss' => '03-18-86-7431-9',
            'nombre' => 'COYOTL CARLOS JOSE MIGUEL',
            'curp' => 'COCM860102HPLYGS02'
        ],
        '61-06-71-5502-8' => [
            'nss' => '61-06-71-5502-8',
            'nombre' => 'COYOTL COYOTL JOSE MIGUEL',
            'curp' => 'COCM710203HPLYYY01'
        ],
        '61-05-84-0851-1' => [
            'nss' => '61-05-84-0851-1',
            'nombre' => 'COYOTL CUAYA AURELIO',
            'curp' => 'COCA840715HPLYYY07'
        ],
        '02-03-81-8963-3' => [
            'nss' => '02-03-81-8963-3',
            'nombre' => 'COYOTL CUAYA DOMINGO',
            'curp' => 'COCD810521HPLYYY00'
        ],
        '04-08-82-4520-6' => [
            'nss' => '04-08-82-4520-6',
            'nombre' => 'COYOTL CUAYA JOSE FILIBERTO',
            'curp' => 'COCF820920HPLYYY05'
        ],
        '03-18-87-3480-4' => [
            'nss' => '03-18-87-3480-4',
            'nombre' => 'COYOTL CUAYA JOSE LUIS',
            'curp' => 'COCL870228HPLYYY00'
        ],
        '61-06-71-5501-0' => [
            'nss' => '61-06-71-5501-0',
            'nombre' => 'COYOTL COYOTL MAXIMILIANO',
            'curp' => 'COCM710424HPLYYY07'
        ],
        '11-15-82-4152-5' => [
            'nss' => '11-15-82-4152-5',
            'nombre' => 'COYOTL COYOTL SIXTO',
            'curp' => 'COCS820815HPLYYY03'
        ],
        '61-06-71-5503-6' => [
            'nss' => '61-06-71-5503-6',
            'nombre' => 'COYOTL HERNANDEZ MAXIMINO',
            'curp' => 'COHM710831HPLYRX05'
        ],
        '40-02-86-4175-6' => [
            'nss' => '40-02-86-4175-6',
            'nombre' => 'COYOTL HERNANDEZ MIGUEL ANGEL',
            'curp' => 'COHM860522HPLYRG03'
        ],
        '61-06-71-5499-7' => [
            'nss' => '61-06-71-5499-7',
            'nombre' => 'COYOTL REYES AURELIO',
            'curp' => 'CORA710716HPLYYY08'
        ],
        '48-96-75-9098-8' => [
            'nss' => '48-96-75-9098-8',
            'nombre' => 'COYOTL REYES CARLOS',
            'curp' => 'CORC751031HPLYYY00'
        ],
        '48-96-75-9099-6' => [
            'nss' => '48-96-75-9099-6',
            'nombre' => 'COYOTL REYES DOMINGO',
            'curp' => 'CORD751103HPLYYY02'
        ],
        '61-06-71-5500-2' => [
            'nss' => '61-06-71-5500-2',
            'nombre' => 'COYOTL REYES FILIBERTO',
            'curp' => 'CORF710929HPLYYY00'
        ],
        '40-02-86-4176-4' => [
            'nss' => '40-02-86-4176-4',
            'nombre' => 'COYOTL REYES JOSE CARLOS',
            'curp' => 'CORC860814HPLYYY00'
        ],
        '06-97-92-7894-5' => [
            'nss' => '06-97-92-7894-5',
            'nombre' => 'COYOTL REYES JOSE FILIBERTO',
            'curp' => 'CORF920817HPLYYY01'
        ],
        '48-96-75-9101-9' => [
            'nss' => '48-96-75-9101-9',
            'nombre' => 'COYOTL REYES MAXIMILIANO',
            'curp' => 'CORM751118HPLYYY06'
        ],
        '03-18-87-3481-2' => [
            'nss' => '03-18-87-3481-2',
            'nombre' => 'CUAYA CUAYA JOSE LUIS',
            'curp' => 'CUCL870429HPLYYY05'
        ],
        '61-05-84-0853-8' => [
            'nss' => '61-05-84-0853-8',
            'nombre' => 'CUAYA CUAYA VICTOR',
            'curp' => 'CUCV840102HPLYYY09'
        ],
        '03-97-92-0894-7' => [
            'nss' => '03-97-92-0894-7',
            'nombre' => 'CUAYA FLORES MARIO',
            'curp' => 'CUFM920827HPLYRL00'
        ],
        '48-06-86-1597-9' => [
            'nss' => '48-06-86-1597-9',
            'nombre' => 'CUAYA LOPEZ CARLOS',
            'curp' => 'CULC861228HPLYPR03'
        ],
        '48-06-86-1595-3' => [
            'nss' => '48-06-86-1595-3',
            'nombre' => 'CUAYA LOPEZ FILIBERTO',
            'curp' => 'CULF860916HPLYPL04'
        ],
        '57-15-95-9469-6' => [
            'nss' => '57-15-95-9469-6',
            'nombre' => 'CUAYA LOPEZ JORGE CARLOS',
            'curp' => 'CULJ950416HPLYPR06'
        ],
        '48-06-86-1598-7' => [
            'nss' => '48-06-86-1598-7',
            'nombre' => 'CUAYA LOPEZ MIGUEL',
            'curp' => 'CULM861010HPLYPL08'
        ],
        '57-15-95-9468-8' => [
            'nss' => '57-15-95-9468-8',
            'nombre' => 'CUAYA LOPEZ VICTOR',
            'curp' => 'CULV951230HPLYPC02'
        ],
        '48-07-88-2624-3' => [
            'nss' => '48-07-88-2624-3',
            'nombre' => 'DE LA CRUZ ACOSTA ARTURO',
            'curp' => 'CRAA880823HMCRRT04'
        ],
        '03-97-92-0892-1' => [
            'nss' => '03-97-92-0892-1',
            'nombre' => 'FLORES FUENTES JOSE ANTONIO',
            'curp' => 'FOFA920409HPLLNN08'
        ],
        '48-07-88-2623-5' => [
            'nss' => '48-07-88-2623-5',
            'nombre' => 'GASCA MARTINEZ FRANCISCO JAVIER',
            'curp' => 'GAMF880423HPLSRR09'
        ],
        '03-97-92-0895-5' => [
            'nss' => '03-97-92-0895-5',
            'nombre' => 'GASGA JIMENEZ JOSE ELIEL',
            'curp' => 'GAJE920303HPLSML03'
        ],
        '17-18-99-6041-9' => [
            'nss' => '17-18-99-6041-9',
            'nombre' => 'HERNANDEZ AVILA MARCO ANTONIO',
            'curp' => 'HEAM990316HPPRRC04'
        ],
        '03-04-75-4631-2' => [
            'nss' => '03-04-75-4631-2',
            'nombre' => 'HERNANDEZ CERVANTES EVARISTO',
            'curp' => 'HECE750821HPLRRV06'
        ],
        '40-02-86-4177-2' => [
            'nss' => '40-02-86-4177-2',
            'nombre' => 'HERNANDEZ COYOTL DOMINGO',
            'curp' => 'HECD860425HPLRYM04'
        ],
        '48-06-86-1594-5' => [
            'nss' => '48-06-86-1594-5',
            'nombre' => 'HERNANDEZ COYOTL MIGUEL',
            'curp' => 'HECM860523HPLRYM09'
        ],
        '48-96-75-9097-0' => [
            'nss' => '48-96-75-9097-0',
            'nombre' => 'HERNANDEZ COYOTL MIGUEL ANGEL',
            'curp' => 'HECM751009HPLRYM05'
        ],
        '17-18-99-6040-1' => [
            'nss' => '17-18-99-6040-1',
            'nombre' => 'HERNANDEZ DIEGO ANTONIO',
            'curp' => 'HEDA990509HPLRGNA1'
        ],
        '48-06-86-1593-7' => [
            'nss' => '48-06-86-1593-7',
            'nombre' => 'LOPEZ CUAYA DOMINGO',
            'curp' => 'LOCD860704HPLPYM01'
        ],
        '48-06-86-1592-9' => [
            'nss' => '48-06-86-1592-9',
            'nombre' => 'LOPEZ CUAYA FILIBERTO',
            'curp' => 'LOCF860821HPLPYL06'
        ],
        '08-18-00-2742-0' => [
            'nss' => '08-18-00-2742-0',
            'nombre' => 'MORALES DAZA CARLOS ALBERTO',
            'curp' => 'MODC000109HPLRZR08'
        ],
        '03-23-01-3169-4' => [
            'nss' => '03-23-01-3169-4',
            'nombre' => 'RAMIREZ DIEGO ALEXIS',
            'curp' => 'RADA010708HPLMGLA6'
        ],
        '03-23-01-3167-8' => [
            'nss' => '03-23-01-3167-8',
            'nombre' => 'RAMIREZ DIEGO JOSE ANTONIO',
            'curp' => 'RADJ010301HPLMGSA9'
        ],
        '61-06-71-5497-1' => [
            'nss' => '61-06-71-5497-1',
            'nombre' => 'REYES COYOTL AURELIO',
            'curp' => 'RECA711103HPLYYR04'
        ],
        '61-06-71-5496-3' => [
            'nss' => '61-06-71-5496-3',
            'nombre' => 'REYES COYOTL CARLOS',
            'curp' => 'RECC710918HPLYYR09'
        ],
        '61-06-71-5495-5' => [
            'nss' => '61-06-71-5495-5',
            'nombre' => 'REYES COYOTL DOMINGO',
            'curp' => 'RECD710730HPLYYM01'
        ],
        '61-06-71-5494-7' => [
            'nss' => '61-06-71-5494-7',
            'nombre' => 'REYES COYOTL FILIBERTO',
            'curp' => 'RECF710412HPLYYL00'
        ],
        '61-06-71-5498-9' => [
            'nss' => '61-06-71-5498-9',
            'nombre' => 'REYES COYOTL MAXIMINO',
            'curp' => 'RECM711205HPLYYX07'
        ],
        '02-03-82-4678-6' => [
            'nss' => '02-03-82-4678-6',
            'nombre' => 'REYES HERNANDEZ CARLOS',
            'curp' => 'REHC820925HPLYHR06'
        ],
        '02-03-82-4679-4' => [
            'nss' => '02-03-82-4679-4',
            'nombre' => 'REYES HERNANDEZ DOMINGO',
            'curp' => 'REHD820728HPLYHR02'
        ],
        '02-03-82-4680-0' => [
            'nss' => '02-03-82-4680-0',
            'nombre' => 'REYES HERNANDEZ FILIBERTO',
            'curp' => 'REHF820630HPLYHR00'
        ],
        '02-03-82-4681-8' => [
            'nss' => '02-03-82-4681-8',
            'nombre' => 'REYES HERNANDEZ MAXIMINO',
            'curp' => 'REHM820601HPLYHR09'
        ],
        '02-03-82-4677-8' => [
            'nss' => '02-03-82-4677-8',
            'nombre' => 'REYES HERNANDEZ MIGUEL',
            'curp' => 'REHM821023HPLYHR05'
        ],
        '61-96-78-0856-1' => [
            'nss' => '61-96-78-0856-1',
            'nombre' => 'SANCHEZ BRIONES ROGELIO',
            'curp' => 'SABR780313HTLNRG06'
        ]
    ];
    
    /**
     * Buscar empleado por NSS
     */
    public static function buscarPorNSS($nss) {
        $nss_limpio = self::limpiarNSS($nss);
        return self::$empleados_conocidos[$nss_limpio] ?? null;
    }
    
    /**
     * Buscar empleado por nombre (coincidencia parcial)
     */
    public static function buscarPorNombre($nombre) {
        $nombre_limpio = self::limpiarNombre($nombre);
        
        foreach (self::$empleados_conocidos as $empleado) {
            $nombre_empleado_limpio = self::limpiarNombre($empleado['nombre']);
            
            // Buscar coincidencia parcial (al menos 70% de similitud)
            $similitud = 0;
            similar_text($nombre_limpio, $nombre_empleado_limpio, $similitud);
            
            if ($similitud >= 70) {
                return $empleado;
            }
            
            // También buscar si contiene las palabras principales
            $palabras_busqueda = explode(' ', $nombre_limpio);
            $palabras_empleado = explode(' ', $nombre_empleado_limpio);
            $coincidencias = 0;
            
            foreach ($palabras_busqueda as $palabra) {
                if (strlen($palabra) > 2 && in_array($palabra, $palabras_empleado)) {
                    $coincidencias++;
                }
            }
            
            // Si al menos 2 palabras coinciden, considerarlo una coincidencia
            if ($coincidencias >= 2) {
                return $empleado;
            }
        }
        
        return null;
    }
    
    /**
     * Buscar empleado por CURP
     */
    public static function buscarPorCURP($curp) {
        $curp_limpio = self::limpiarCURP($curp);
        
        foreach (self::$empleados_conocidos as $empleado) {
            if ($empleado['curp'] === $curp_limpio) {
                return $empleado;
            }
        }
        
        return null;
    }
    
    /**
     * Extraer información de empleados del texto del PDF
     */
    public static function extraerEmpleadosDelTexto($texto_pdf) {
        $empleados_encontrados = [];
        $lineas = explode("\n", $texto_pdf);
        
        foreach ($lineas as $linea) {
            $linea_limpia = trim($linea);
            
            // Buscar patrones de NSS (XX-XX-XX-XXXX-X)
            if (preg_match('/(\d{2}-\d{2}-\d{2}-\d{4}-\d)/', $linea_limpia, $matches)) {
                $nss_encontrado = $matches[1];
                $empleado = self::buscarPorNSS($nss_encontrado);
                
                if ($empleado && !self::yaEstaEnLista($empleados_encontrados, $empleado['nss'])) {
                    $empleados_encontrados[] = $empleado;
                }
            }
            
            // Buscar patrones de CURP
            if (preg_match('/([A-Z]{4}\d{6}[HM][A-Z]{2}[A-Z0-9]{3})/', $linea_limpia, $matches)) {
                $curp_encontrado = $matches[1];
                $empleado = self::buscarPorCURP($curp_encontrado);
                
                if ($empleado && !self::yaEstaEnLista($empleados_encontrados, $empleado['nss'])) {
                    $empleados_encontrados[] = $empleado;
                }
            }
            
            // Buscar por nombres (buscar en cada línea palabras que puedan ser nombres)
            foreach (self::$empleados_conocidos as $empleado_conocido) {
                $apellidos = explode(' ', $empleado_conocido['nombre']);
                $apellido_principal = $apellidos[0] ?? '';
                
                if (strlen($apellido_principal) > 3 && 
                    stripos($linea_limpia, $apellido_principal) !== false &&
                    !self::yaEstaEnLista($empleados_encontrados, $empleado_conocido['nss'])) {
                    
                    // Verificar que realmente es el empleado buscando más coincidencias
                    $coincidencias = 0;
                    foreach ($apellidos as $apellido) {
                        if (strlen($apellido) > 2 && stripos($linea_limpia, $apellido) !== false) {
                            $coincidencias++;
                        }
                    }
                    
                    if ($coincidencias >= 2) {
                        $empleados_encontrados[] = $empleado_conocido;
                    }
                }
            }
        }
        
        return $empleados_encontrados;
    }
    
    /**
     * Obtener todos los empleados conocidos
     */
    public static function obtenerTodosLosEmpleados() {
        return array_values(self::$empleados_conocidos);
    }
    
    /**
     * Agregar nuevo empleado al mapeo
     */
    public static function agregarEmpleado($nss, $nombre, $curp) {
        $nss_limpio = self::limpiarNSS($nss);
        self::$empleados_conocidos[$nss_limpio] = [
            'nss' => $nss_limpio,
            'nombre' => strtoupper(trim($nombre)),
            'curp' => self::limpiarCURP($curp)
        ];
    }
    
    // Métodos auxiliares privados
    private static function limpiarNSS($nss) {
        return preg_replace('/[^0-9\-]/', '', $nss);
    }
    
    private static function limpiarNombre($nombre) {
        return strtoupper(preg_replace('/[^A-ZÑ\s]/', '', strtoupper($nombre)));
    }
    
    private static function limpiarCURP($curp) {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($curp)));
    }
    
    private static function yaEstaEnLista($lista, $nss) {
        foreach ($lista as $empleado) {
            if ($empleado['nss'] === $nss) {
                return true;
            }
        }
        return false;
    }
}
?>
