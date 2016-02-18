<?php
//SFTP
$ftpFilePut = 'file.ext';
$command = "export SSHPASS='password'
                sshpass -e sftp -oBatchMode=no -b - ftpuser@sftp-server.com <<EOF
				cd incoming
				cd grfs
				put $ftpFilePut
                ls -l
                bye
                EOF";
				
				

    $proc=proc_open($command,
					array(
						   array("pipe","r"),
						   array("pipe","w"),
						   array("pipe","w")
					     ),
					$pipes);

	$errorString = stream_get_contents($pipes[2]);
	$outString = stream_get_contents($pipes[1]);
	fclose($pipes[2]);
	fclose($pipes[1]);

	$returnVal = proc_close($proc); 

	echo "Execution Status: $returnVal \n";

	if($returnVal != 0) {
		echo $errorString."\n";
		$errors = true;
		$errorMessage = $errorString;
	}
	else {
		echo "Output Data: \n".$outString."\n";
	}
//END SFTP

//MOUNT

   
	/** Error reporting */
    error_reporting(E_ALL);
    $startTime = microtime(true);
	//-----------------------------------------------------------------------------------------------------------------------------

	$error = true;
	$ToList = "user@users.com";
	//$ToList = "jherrera@uno.com.do";
	
	$headers  = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
	$headers .= "From: watcher@whatch.com". "\r\n";
	
	$subject = "Uploading Files to Database [Error]";
	
	$ErrorMsg = "";
	$filename  = "file.csv";
	$MountedPath = "/media/files";
	$delimiter = ",";
	$sleepTime = 1800;
	
	while($error == true)
	{
		$error = false;
		$ErrorMsg = "";
				
		$descriptorspec = array(
									0 => array("pipe","r"),
									1 => array("pipe","w"),
									2 => array("pipe","w")
								);

		//Montando el directorio donde esta el archivo
		$process = proc_open("mount \"//$IP/Directory\" $MountedPath -o rw,username='username',password='password'", $descriptorspec, $pipes) ;
		
		if (is_resource($process)) 
		{
		  $MountedError = "";
		  $MountedError = fgets($pipes[2], 2048);
		  fclose($pipes[2]);
		  
		  if($MountedError) //Ocurrio un error montando la unidad remota.
		  {
			$ErrorMsg = "Error mounting directory $MountedError";
			echo "$ErrorMsg \n";
			
			$error = true;
			mail($ToList,$subject,$ErrorMsg,$headers);
			
			sleep($sleepTime);
			continue;
		  }
		}
		
		proc_close($process);
		echo "Directory $MountedPath mounted. \n";
		
		//Copiando el Archivo localmente.
		if (!copy("$MountedPath/$filename", $filename)) //Ocurrio un error copiando el archivo al servidor local
		{
			$ErrorMsg = "Error Copying $filename ".print_r(error_get_last());
			echo "$ErrorMsg \n";
			
			$error = true;
			mail($ToList,$subject, $ErrorMsg, $headers);
			
			sleep($sleepTime);
			continue;
		}
		echo "File: $filename has been copied. \n";
		
		//Desmontando la unidad luego de copiar el archivo de extensiones.
		$process = proc_open("umount $MountedPath", $descriptorspec, $pipes) ;
		
		if (is_resource($process)) 
		{
		  $MountedError = "";
		  $MountedError = fgets($pipes[2], 2048);
		  fclose($pipes[2]);
		  
		  if($MountedError) //Ocurrio un error desmontando la unidad remota.
		  {
			$ErrorMsg = "Error can not unmount directory  $MountedError";
			echo "$ErrorMsg \n";
			
			$error = true;
			mail($ToList,$subject,$ErrorMsg,$headers);
			
			sleep($sleepTime);
			continue;
		  }
		}
		
		proc_close($process);
		echo "Directory $MountedPath has been unmounted. \n";		
		
		if(!file_exists($filename)) //No existe el archivo de las extensiones.
		{
			$ErrorMsg = "Expected File ($filename) does not exists.";
			echo "$ErrorMsg \n";
			
			$error = true;
			
			mail($ToList,$subject,$ErrorMsg,$headers);
			sleep($sleepTime);
			continue;
		}
		
		if(!is_readable($filename)) //El archivo no se puede leer
		{
			$ErrorMsg = "File ($filename) is not a readable file.";
			echo "$ErrorMsg \n";
			
			$error = true;

			mail($ToList,$subject,$ErrorMsg,$headers);
			sleep($sleepTime);
			continue;
		}
	
		if (($file = fopen($filename, 'r')) === FALSE) //No se puede abrir el archivo.
		{
			$ErrorMsg = "File ($filename) could not be open. \n".print_r(error_get_last(),true);
			echo "$ErrorMsg \n";
			
			$error = true;

			mail($ToList,$subject,$ErrorMsg,$headers);
			sleep($sleepTime);
			continue;
		}
		
		$i=0;
		$ExtensionsInserted = 0;
		$link = lib_pg::conectar_diamante();
		lib_pg::ejecutar($link,"BEGIN;");
		
		lib_pg::ejecutar($link,"DELETE from schema.table where COALESCE(field, false) = false;");
		
		while (($row = fgetcsv($file, 1000, $delimiter)) !== FALSE)
		{
			$i++;
			
			if($i > 3)
			{	
				$Extensions = array(3000, 4610, 4615, 4616, 4621, 4782, 4783, 5000);
				
				if(in_array($row[0],$Extensions) or ($row[0] >= 3101 and $row[0] <= 3299) or ($row[0] >= 3305 and $row[0] <= 3314))
				{
				  $sql = "insert into schema.table( field, name) values ($1, $2);";	
				  $result =  pg_query_params($link,$sql,array($row[0],$row[2]));
				  
				  if(!$result) //Ocurio un error insertando las extensiones.
				  {
					$ErrorMsg = "Error inserting files to database. File Line: $i. RollBack Transaction. ".pg_last_error($link);
					echo "$ErrorMsg \n";
					$error = true;

					mail($ToList,$subject,$ErrorMsg,$headers);
					
					lib_pg::ejecutar($link, "ROLLBACK;");
					
					exit;
				  }
				  $ExtensionsInserted++;
				}
			}
		}
		fclose($file);
		
		lib_pg::ejecutar($link, "COMMIT;");
		
		pg_close($link);
		
		echo "$ExtensionsInserted files inserted in the database. \n";
	}
	
	$msg = "";
	$msg .= "Elapsed time is: ". (microtime(true) - $startTime) ." seconds <p></p>\n";
	$msg .= date('H:i:s')." Memory Used: ".(memory_get_peak_usage(true) / 1024 / 1024)." MB\n";

    echo $msg;
	
//MOUNT
?>
	
