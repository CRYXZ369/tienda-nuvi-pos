#define SourceRoot AddBackslash(SourcePath) + "..\..\"

[Setup]
AppId={{A581AF09-111C-4EB2-9538-02C42293DA02}
AppName=Tienda Nuvi POS
AppVersion=1.2.0
AppPublisher=Tienda Nuvi
DefaultDirName=C:\xampp\htdocs\tienda-nuvi
DisableDirPage=yes
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#SourceRoot}dist
OutputBaseFilename=setup_POS_nuvi
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
LicenseFile={#SourceRoot}LICENSE

[Files]
Source: "{#SourceRoot}*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: ".git\*,dist\*,backups\*,logs\*,releases\*,recursos\*,RESPALDO UBUNTU\*,documentacion\historico\backup_innecesarios_2026-04-22\*,config\local.php,config\backup.local.env,config\ticket.local.php,.env*,tienda_nuvi.backup.sql"

[Code]
function InitializeSetup(): Boolean;
begin
	if not FileExists('C:\xampp\php\php.exe') or
	   not FileExists('C:\xampp\mysql\bin\mysql.exe') then
	begin
		Log('Falta XAMPP en C:\xampp; no se copiaron archivos.');
		if not WizardSilent then
			MsgBox('Antes de instalar Nuvi, instala XAMPP en C:\xampp e inicia Apache y MySQL. No se copio ningun archivo.', mbError, MB_OK);
		Result := False;
		exit;
	end;
	Result := True;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
	SearchRec: TFindRec;
	HasFiles: Boolean;
begin
	HasFiles := False;
	if DirExists(ExpandConstant('{app}')) and FindFirst(ExpandConstant('{app}\*'), SearchRec) then
	begin
		repeat
			if (SearchRec.Name <> '.') and (SearchRec.Name <> '..') then
				HasFiles := True;
		until not FindNext(SearchRec);
		FindClose(SearchRec);
	end;
	if HasFiles then
	begin
		Log('La carpeta destino contiene archivos; no se sobrescribio nada.');
		Result := 'La carpeta destino ya contiene archivos. No se sobrescribio nada.';
	end
	else
		Result := '';
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
	ResultCode: Integer;
	Parameters: String;
begin
	if CurStep = ssPostInstall then
	begin
		Parameters := '-NoProfile -ExecutionPolicy Bypass -File "' +
			ExpandConstant('{app}\installer\windows\install_windows.ps1') +
			'" -AppRoot "' + ExpandConstant('{app}') + '"';
		if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
			Parameters, '', SW_SHOWNORMAL, ewWaitUntilTerminated, ResultCode) then
			RaiseException('No se pudo iniciar la configuracion. La instalacion no esta lista; revisa los archivos y la base de datos antes de reintentar.');
		if ResultCode <> 0 then
			RaiseException('La configuracion fallo (codigo ' + IntToStr(ResultCode) +
				'). La instalacion no esta lista; revisa los mensajes, archivos y base de datos antes de reintentar.');
	end;
end;