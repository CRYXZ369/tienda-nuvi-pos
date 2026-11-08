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

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\windows\install_windows.ps1"" -AppRoot ""{app}"""; Description: "Configurar base de datos y administrador"; Flags: waituntilterminated

[Code]
function InitializeSetup(): Boolean;
var
	SearchRec: TFindRec;
	HasFiles: Boolean;
begin
	if not FileExists('C:\xampp\php\php.exe') or
	   not FileExists('C:\xampp\mysql\bin\mysql.exe') then
	begin
		MsgBox('Antes de instalar Nuvi, instala XAMPP en C:\xampp e inicia Apache y MySQL. No se copio ningun archivo.', mbError, MB_OK);
		Result := False;
		exit;
	end;
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
		MsgBox('La carpeta destino ya contiene archivos. No se sobrescribio nada.', mbError, MB_OK);
		Result := False;
	end
	else
		Result := True;
end;