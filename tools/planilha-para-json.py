#!/usr/bin/env python3
"""
Converte a planilha de planejamento (PCA_2027.xlsx: abas STI e CJF; "Removidos" é ignorada) em um JSON enxuto
para tools/complementar-da-planilha.php. A destinação (que não está na planilha) vem de dados/pca_2027.json,
pelo N.PCA.   Uso: python3 planilha-para-json.py PCA_2027.xlsx [dados/pca_2027.json] > planilha-pca-2027.json
"""
import json
import re
import sys

import openpyxl


def norm(h):
    return re.sub(r"\s+", " ", str(h or "")).strip().lower()


def limpa(v):
    return re.sub(r"\s+", " ", str(v)).strip() if v is not None else ""


def main():
    wb = openpyxl.load_workbook(sys.argv[1], data_only=True)
    dest = {}
    if len(sys.argv) > 2:
        for x in json.load(open(sys.argv[2]))["contratacoes"]:
            if x.get("destinacao"):
                dest[str(x["numero_pca"])] = x["destinacao"]
    out = []
    for ws in wb:
        if ws.title.lower().startswith("removid"):
            continue
        it = ws.iter_rows(values_only=True)
        next(it)
        hdr = [norm(h) for h in next(it)]
        for r in it:
            d = dict(zip(hdr, r))
            m = re.search(r"(\d+)\s*$", str(d.get("n. pctic") or ""))
            if not m or not d.get("apelido"):
                continue
            n_pca = limpa(d.get("n.pca"))
            req = re.sub(r"\s*[\n/]+\s*", "/", str(d.get("requisitante") or "").strip())
            out.append({
                "aba": ws.title,
                "pctic": str(int(m.group(1))),
                "n_pca": n_pca if n_pca.isdigit() else "",
                "apelido": limpa(d.get("apelido")),
                "requisitante": limpa(req),
                "status": limpa(d.get("status")),
                "observacoes": str(d.get("observações") or "").strip(),
                "destinacao": dest.get(n_pca, ""),
                # coluna "Orçamento (JF ou Corporativo)" (só a aba STI): CJF/JF, CJF ou JF
                "orcamento": limpa(next((v for k, v in d.items() if str(k).startswith("orçamento")), "")).upper(),
            })
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)


main()
