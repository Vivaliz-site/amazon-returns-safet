# Acesso direto dos agentes as VMs

Instrucao operacional obrigatoria para agentes que precisem executar comandos nas VMs Oracle Cloud da ShopVivaliz. Nunca versionar, imprimir ou copiar para logs/prompts chaves privadas, tokens, senhas, headers de autenticacao ou conteudo de `security_token_file`.

## VMs atuais

| Alias | Instancia OCI | SSH no Fred-Win |
| --- | --- | --- |
| `vm1` | `shopvivaliz-free-a1` | `shopvivaliz-a1-site-raw` |
| `vm2` | `always-free-arm-1787907847-26` | `shopvivaliz-a1-backend-raw` |

- Regiao OCI: `sa-saopaulo-1`.
- Controlador canonico: Fred-Win (`LAPTOP-NIG4IFUU`).
- Perfil OCI obrigatorio: `AGENTS`.
- Config OCI protegida: `C:\Users\FRED\.oci\agents\config`.
- Helper OCI: `C:\Users\FRED\.local\bin\sv-oci-vm-run.ps1`.
- Executavel SSH canonico no Fred-Win: `C:\Program Files\Git\usr\bin\ssh.exe`.

## Ordem obrigatoria de acesso

1. **Remote Desktop Commander** diretamente pelo `device_name`, quando online, para operacao comum sem privilegio elevado.
2. **SSH administrativo pelo Fred-Win**, usando o SSH do Git e os aliases cadastrados, quando a tarefa exigir `sudo`/root.
3. **OCI Compute Instance Run Command**, via perfil `AGENTS`, como shell de fallback independente de SSH e de porta de entrada.
4. **OCI serial console** somente como ultimo recurso de recuperacao.

Nao enfraquecer os bloqueios de `sudo`/`NoNewPrivileges` do Remote Desktop Commander para conseguir root.

## Shell direto via OCI

Preferir o helper validado:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File C:\Users\FRED\.local\bin\sv-oci-vm-run.ps1 vm1 "hostname; id -un; pwd"
powershell -NoProfile -ExecutionPolicy Bypass -File C:\Users\FRED\.local\bin\sv-oci-vm-run.ps1 vm2 "hostname; id -un; pwd"
```

O helper usa somente `C:\Users\FRED\.oci\agents\config` + perfil `AGENTS`; nunca usar `DEFAULT` como fallback.

### Privilegio do Run Command

- O Run Command executa como `ocarun` (UID 999) nas duas VMs.
- Em validacao real de 2026-09-06, `sudo -n` como `ocarun` exigiu senha nas duas VMs; nao presuma root pelo token OCI.
- Use Run Command para diagnostico e shell sem root. Para operacao administrativa, use o SSH autorizado ou o console serial.
- `ACCEPTED` nao prova execucao. Sucesso exige `SUCCEEDED`, stdout coerente e exit code `0`.
- O polling do Oracle Agent pode levar alguns minutos; nao relance comandos duplicados apenas por permanecerem em `ACCEPTED` por um ciclo.

## SSH administrativo canonico

No Fred-Win, usar o OpenSSH distribuido com Git:

```powershell
& 'C:\Program Files\Git\usr\bin\ssh.exe' -o BatchMode=yes shopvivaliz-a1-site-raw 'hostname; id -un; sudo -n id -u'
& 'C:\Program Files\Git\usr\bin\ssh.exe' -o BatchMode=yes shopvivaliz-a1-backend-raw 'hostname; id -un; sudo -n id -u'
```

Validacao real de 2026-09-06: ambos os aliases autenticaram e `sudo -n id -u` retornou `0` com exit code local `0`. Na sessao automatizada do Fred-Win, o SSH do Windows retornou exit `255` sem stderr, enquanto o SSH do Git funcionou; ate nova validacao, preferir o SSH do Git. Nunca usar `StrictHostKeyChecking=no`.

## Remote Desktop Commander

Device names atuais:

- `shopvivaliz-free-a1`
- `always-free-arm-1787907847-26`

O DC e o caminho mais simples para comandos sem root. Seu bloqueio de comandos privilegiados e intencional; nao alterar essa politica como atalho operacional.

## Estado validado em 2026-09-06

- Oracle Cloud Agent e plugin `Compute Instance Run Command`: ativos nas duas instancias.
- `sv-oci-vm-run vm1`: `SUCCEEDED`, hostname `shopvivaliz-free-a1`, usuario `ocarun`, exit code `0`.
- `sv-oci-vm-run vm2`: `SUCCEEDED`, hostname `always-free-arm-1787907847-26`, usuario `ocarun`, exit code `0`.
- SSH Git + `shopvivaliz-a1-site-raw`: acesso administrativo com `sudo` validado.
- SSH Git + `shopvivaliz-a1-backend-raw`: acesso administrativo com `sudo` validado.

## Seguranca e verificacao

- Nunca copiar chave privada OCI ou SSH para as VMs por conveniencia.
- Nunca incluir segredo no texto do Run Command.
- Se Run Command ficar parado, revisar o plugin e `/var/log/oracle-cloud-agent/plugins/runcommand/runcommand.log`.
- Depois de qualquer mudanca, confirmar `hostname`, resultado funcional e exit code antes de declarar sucesso.