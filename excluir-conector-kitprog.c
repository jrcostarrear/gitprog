/*
 * excluir-conector-kitprog.c
 *
 * Remove de um kit de conectores SISC todos os arquivos gerados para um
 * conector especifico, sem usar rm via shell e sem seguir links simbolicos.
 *
 * Uso:
 *   gcc -O2 -Wall -Wextra -o excluir-conector-kitprog excluir-conector-kitprog.c
 *   ./excluir-conector-kitprog [--dry-run] conector-nome
 *
 * Observacao: a limpeza ignora .git; historico Git deve ser tratado com git se
 * for necessario apagar vestigios do repositorio remoto/historico.
 */
#define _XOPEN_SOURCE 700
#include <ctype.h>
#include <dirent.h>
#include <errno.h>
#include <limits.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

static int dry_run = 0;
static long removidos = 0;
static long alterados = 0;
static long erros = 0;
static long vestigios = 0;

static int starts_with(const char *s, const char *p) {
    return s && p && strncmp(s, p, strlen(p)) == 0;
}

static const char *base_name(const char *p) {
    const char *b = strrchr(p ? p : "", '/');
    return b ? b + 1 : (p ? p : "");
}

static void log_err(const char *fmt, ...) {
    va_list ap;
    erros++;
    fprintf(stderr, "[ERRO] ");
    va_start(ap, fmt);
    vfprintf(stderr, fmt, ap);
    va_end(ap);
    fprintf(stderr, "\n");
}

static int nome_conector_valido(const char *nome) {
    size_t n;
    if (!nome || !starts_with(nome, "conector-")) return 0;
    n = strlen(nome);
    if (n < strlen("conector-a") || n > 160) return 0;
    if (nome[n - 1] == '-') return 0;
    for (size_t i = 0; i < n; i++) {
        unsigned char c = (unsigned char)nome[i];
        if (!(c == '-' || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9'))) return 0;
        if (c == '-' && i > 0 && nome[i - 1] == '-') return 0;
    }
    return 1;
}

static int path_join(char *out, size_t tam, const char *a, const char *b) {
    int n;
    if (!out || tam == 0 || !a || !b) return -1;
    n = snprintf(out, tam, "%s/%s", a, b);
    return n > 0 && (size_t)n < tam ? 0 : -1;
}

static int lstat_ok(const char *path, struct stat *st) {
    if (lstat(path, st) == 0) return 1;
    if (errno != ENOENT) log_err("lstat falhou: %s: %s", path, strerror(errno));
    return 0;
}

static int delete_tree(const char *path) {
    struct stat st;
    DIR *d;
    struct dirent *e;
    int rc = 0;

    if (!path || strcmp(path, ".") == 0 || strcmp(path, "/") == 0) return -1;
    if (strcmp(base_name(path), ".git") == 0) return 0;
    if (!lstat_ok(path, &st)) return 0;

    if (S_ISDIR(st.st_mode) && !S_ISLNK(st.st_mode)) {
        d = opendir(path);
        if (!d) { log_err("opendir falhou: %s: %s", path, strerror(errno)); return -1; }
        while ((e = readdir(d)) != NULL) {
            char sub[PATH_MAX];
            if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0) continue;
            if (path_join(sub, sizeof(sub), path, e->d_name) != 0) { log_err("caminho longo em %s", path); rc = -1; continue; }
            if (delete_tree(sub) != 0) rc = -1;
        }
        closedir(d);
        printf("%s diretorio: %s\n", dry_run ? "[DRY-RUN] removeria" : "[REMOVER]", path);
        if (!dry_run && rmdir(path) != 0 && errno != ENOENT) { log_err("rmdir falhou: %s: %s", path, strerror(errno)); rc = -1; }
        else removidos++;
    } else {
        printf("%s arquivo: %s\n", dry_run ? "[DRY-RUN] removeria" : "[REMOVER]", path);
        if (!dry_run && unlink(path) != 0 && errno != ENOENT) { log_err("unlink falhou: %s: %s", path, strerror(errno)); rc = -1; }
        else removidos++;
    }
    return rc;
}

static char *read_file(const char *path, size_t *tam_out) {
    FILE *f;
    long sz;
    size_t n;
    char *buf;
    f = fopen(path, "rb");
    if (!f) return NULL;
    if (fseek(f, 0, SEEK_END) != 0) { fclose(f); return NULL; }
    sz = ftell(f);
    if (sz < 0 || sz > 20L * 1024L * 1024L) { fclose(f); return NULL; }
    rewind(f);
    buf = (char *)malloc((size_t)sz + 1);
    if (!buf) { fclose(f); return NULL; }
    n = fread(buf, 1, (size_t)sz, f);
    fclose(f);
    if (n != (size_t)sz) { free(buf); return NULL; }
    buf[n] = '\0';
    if (tam_out) *tam_out = n;
    return buf;
}

static int is_probably_text(const char *buf, size_t n) {
    size_t ctrl = 0;
    if (!buf) return 0;
    for (size_t i = 0; i < n; i++) {
        unsigned char c = (unsigned char)buf[i];
        if (c == 0) return 0;
        if (c < 32 && c != '\n' && c != '\r' && c != '\t') ctrl++;
    }
    return ctrl < 8;
}

static char *replace_all(const char *src, const char *needle, const char *rep, int *changed) {
    size_t sn, nn, rn, count = 0, outsz;
    const char *p;
    char *out, *w;
    if (!src || !needle || !*needle || !rep) return NULL;
    sn = strlen(src); nn = strlen(needle); rn = strlen(rep);
    for (p = src; (p = strstr(p, needle)) != NULL; p += nn) count++;
    if (count == 0) {
        out = strdup(src);
        return out;
    }
    if (changed) *changed = 1;
    outsz = sn + count * (rn > nn ? rn - nn : 0) + 1;
    out = (char *)malloc(outsz);
    if (!out) return NULL;
    w = out; p = src;
    while (1) {
        const char *m = strstr(p, needle);
        size_t chunk;
        if (!m) break;
        chunk = (size_t)(m - p);
        memcpy(w, p, chunk); w += chunk;
        memcpy(w, rep, rn); w += rn;
        p = m + nn;
    }
    strcpy(w, p);
    return out;
}

static int scrub_file(const char *path, const char *nome) {
    size_t n = 0;
    char *buf = read_file(path, &n);
    char *tmp1, *tmp2, *tmp3;
    char marcador[256];
    int changed = 0;
    FILE *f;

    if (!buf) return 0;
    if (!is_probably_text(buf, n)) { free(buf); return 0; }

    snprintf(marcador, sizeof(marcador), "[conector-removido]");
    tmp1 = replace_all(buf, nome, marcador, &changed);
    free(buf);
    if (!tmp1) return -1;

    char destino[256];
    snprintf(destino, sizeof(destino), "conector__%s", nome);
    tmp2 = replace_all(tmp1, destino, "conector__[conector-removido]", &changed);
    free(tmp1);
    if (!tmp2) return -1;

    char catalogo[256];
    snprintf(catalogo, sizeof(catalogo), "catalogo-%s", nome);
    tmp3 = replace_all(tmp2, catalogo, "catalogo-[conector-removido]", &changed);
    free(tmp2);
    if (!tmp3) return -1;

    if (changed) {
        printf("%s vestigio textual em: %s\n", dry_run ? "[DRY-RUN] limparia" : "[LIMPAR]", path);
        if (!dry_run) {
            f = fopen(path, "wb");
            if (!f) { log_err("nao foi possivel gravar %s: %s", path, strerror(errno)); free(tmp3); return -1; }
            fwrite(tmp3, 1, strlen(tmp3), f);
            fclose(f);
        }
        alterados++;
    }
    free(tmp3);
    return 0;
}

static int limpar_token_sisc_arquivo(const char *path, const char *nome) {
    size_t n = 0, prefix_len;
    char *buf = read_file(path, &n);
    char *out;
    const char *p;
    int changed = 0;
    FILE *f;
    if (!buf || !is_probably_text(buf, n)) { free(buf); return 0; }
    out = (char *)malloc(n + 1);
    if (!out) { free(buf); return -1; }
    size_t outlen = 0;
    out[0] = '\0';
    p = buf;
    prefix_len = strlen(nome);
    while (*p) {
        const char *e = strchr(p, '\n');
        size_t original_len = e ? (size_t)(e - p + 1) : strlen(p);
        size_t len = e ? (size_t)(e - p) : strlen(p);
        const char *s = p;
        while (len > 0 && (*s == ' ' || *s == '\t')) { s++; len--; }
        if (len > prefix_len && strncmp(s, nome, prefix_len) == 0 && s[prefix_len] == '~') {
            changed = 1;
        } else {
            memcpy(out + outlen, p, original_len);
            outlen += original_len;
            out[outlen] = '\0';
        }
        if (!e) break;
        p = e + 1;
    }
    free(buf);
    if (changed) {
        printf("%s linha de token em: %s\n", dry_run ? "[DRY-RUN] removeria" : "[LIMPAR]", path);
        if (!dry_run) {
            f = fopen(path, "wb");
            if (!f) { log_err("nao foi possivel gravar %s: %s", path, strerror(errno)); free(out); return -1; }
            fwrite(out, 1, strlen(out), f);
            fclose(f);
        }
        alterados++;
    }
    free(out);
    return 0;
}

static void limpar_token_sisc_dir(const char *dir, const char *nome) {
    DIR *d;
    struct dirent *e;
    d = opendir(dir);
    if (!d) return;
    while ((e = readdir(d)) != NULL) {
        char p[PATH_MAX];
        struct stat st;
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0) continue;
        if (path_join(p, sizeof(p), dir, e->d_name) != 0) continue;
        if (lstat(p, &st) == 0 && S_ISREG(st.st_mode)) limpar_token_sisc_arquivo(p, nome);
    }
    closedir(d);
}

static int path_deve_remover(const char *path, const char *nome) {
    const char *b = base_name(path);
    return strstr(b, nome) != NULL;
}

static void scan_remove_by_name(const char *dir, const char *nome) {
    DIR *d;
    struct dirent *e;
    d = opendir(dir);
    if (!d) return;
    while ((e = readdir(d)) != NULL) {
        char p[PATH_MAX];
        struct stat st;
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || strcmp(e->d_name, ".git") == 0) continue;
        if (path_join(p, sizeof(p), dir, e->d_name) != 0) { log_err("caminho longo em %s", dir); continue; }
        if (!lstat_ok(p, &st)) continue;
        if (path_deve_remover(p, nome)) {
            delete_tree(p);
            continue;
        }
        if (S_ISDIR(st.st_mode) && !S_ISLNK(st.st_mode)) scan_remove_by_name(p, nome);
    }
    closedir(d);
}

static void scan_scrub_textos(const char *dir, const char *nome) {
    DIR *d;
    struct dirent *e;
    d = opendir(dir);
    if (!d) return;
    while ((e = readdir(d)) != NULL) {
        char p[PATH_MAX];
        struct stat st;
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || strcmp(e->d_name, ".git") == 0) continue;
        if (path_join(p, sizeof(p), dir, e->d_name) != 0) { log_err("caminho longo em %s", dir); continue; }
        if (!lstat_ok(p, &st)) continue;
        if (S_ISDIR(st.st_mode) && !S_ISLNK(st.st_mode)) scan_scrub_textos(p, nome);
        else if (S_ISREG(st.st_mode)) scrub_file(p, nome);
    }
    closedir(d);
}

static int file_contains(const char *path, const char *needle) {
    size_t n = 0;
    char *buf = read_file(path, &n);
    int ok;
    if (!buf) return 0;
    ok = is_probably_text(buf, n) && strstr(buf, needle) != NULL;
    free(buf);
    return ok;
}

static void scan_vestigios(const char *dir, const char *nome) {
    DIR *d;
    struct dirent *e;
    d = opendir(dir);
    if (!d) return;
    while ((e = readdir(d)) != NULL) {
        char p[PATH_MAX];
        struct stat st;
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || strcmp(e->d_name, ".git") == 0) continue;
        if (path_join(p, sizeof(p), dir, e->d_name) != 0) continue;
        if (!lstat_ok(p, &st)) continue;
        if (strstr(e->d_name, nome) != NULL) {
            printf("[VESTIGIO] nome no caminho: %s\n", p);
            vestigios++;
        }
        if (S_ISDIR(st.st_mode) && !S_ISLNK(st.st_mode)) scan_vestigios(p, nome);
        else if (S_ISREG(st.st_mode) && file_contains(p, nome)) {
            printf("[VESTIGIO] texto em: %s\n", p);
            vestigios++;
        }
    }
    closedir(d);
}

static void remove_empty_dirs(const char *dir) {
    DIR *d;
    struct dirent *e;
    d = opendir(dir);
    if (!d) return;
    while ((e = readdir(d)) != NULL) {
        char p[PATH_MAX];
        struct stat st;
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || strcmp(e->d_name, ".git") == 0) continue;
        if (path_join(p, sizeof(p), dir, e->d_name) != 0) continue;
        if (lstat(p, &st) == 0 && S_ISDIR(st.st_mode) && !S_ISLNK(st.st_mode)) remove_empty_dirs(p);
    }
    closedir(d);
    if (strcmp(dir, ".") != 0 && strcmp(dir, "./") != 0) {
        if (dry_run) return;
        if (rmdir(dir) == 0) removidos++;
    }
}

static void delete_path_fmt(const char *fmt, const char *nome) {
    char p[PATH_MAX];
    int n = snprintf(p, sizeof(p), fmt, nome);
    if (n > 0 && (size_t)n < sizeof(p)) delete_tree(p);
}

static int checar_raiz_kit(void) {
    struct stat st;
    if (lstat("validar-conector.php", &st) != 0 || !S_ISREG(st.st_mode)) return 0;
    if (lstat("conectores", &st) != 0 || !S_ISDIR(st.st_mode)) return 0;
    if (lstat("siscconectores/web-api", &st) != 0 || !S_ISDIR(st.st_mode)) return 0;
    return 1;
}

static void uso(const char *argv0) {
    fprintf(stderr,
        "Uso:\n"
        "  %s [--dry-run] conector-nome\n\n"
        "Remove do kit os arquivos do conector informado e limpa referencias textuais restantes.\n"
        "Execute a partir da raiz do kit, por exemplo /var/www/html/sisc/kitprog.\n",
        argv0 ? argv0 : "./excluir-conector-kitprog");
}

int main(int argc, char **argv) {
    const char *nome = NULL;

    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--dry-run") == 0 || strcmp(argv[i], "-n") == 0) dry_run = 1;
        else if (!nome) nome = argv[i];
        else { uso(argv[0]); return 2; }
    }

    if (!nome || !nome_conector_valido(nome)) {
        uso(argv[0]);
        fprintf(stderr, "Nome invalido. Use conector-<nome> com letras minusculas, numeros e hifens.\n");
        return 2;
    }
    if (!checar_raiz_kit()) {
        fprintf(stderr, "Diretorio atual nao parece a raiz do kit de conectores.\n");
        return 2;
    }

    printf("Excluindo conector do kit: %s%s\n", nome, dry_run ? " (dry-run)" : "");

    delete_path_fmt("conectores/%s", nome);
    delete_path_fmt("siscconectores/web-api/catalogo-%s.json", nome);
    delete_path_fmt("web-api/catalogo-%s.json", nome);
    delete_path_fmt("siscconectores/%s-uso.php", nome);
    delete_path_fmt("siscconectores/%s-cliente.php", nome);
    delete_path_fmt("siscconectores/secretos/%s.json", nome);
    delete_path_fmt("siscconectores/secretos/%s.sample.json", nome);
    delete_path_fmt("secretos/%s.json", nome);
    delete_path_fmt("secretos/%s.sample.json", nome);
    limpar_token_sisc_dir("token-sisc", nome);
    limpar_token_sisc_dir("siscconectores/token-sisc", nome);

    scan_remove_by_name(".", nome);
    scan_scrub_textos(".", nome);
    remove_empty_dirs(".");
    if (!dry_run) scan_vestigios(".", nome);

    printf("Resumo: removidos=%ld arquivos_texto_limpos=%ld erros=%ld vestigios_restantes=%ld\n", removidos, alterados, erros, vestigios);
    return (erros || vestigios) ? 1 : 0;
}
