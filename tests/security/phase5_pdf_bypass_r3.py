# Round-3 extra bypass samples for PdfActiveContentScanner. Usage: python3 phase5_pdf_bypass_r3.py OUTDIR
import zlib,sys,os
D=sys.argv[1]
def pdf(objs):
    out=b"%PDF-1.7\n";offs=[]
    for i,o in enumerate(objs,1):
        offs.append(len(out)); out+=b"%d 0 obj\n"%i+o+b"\nendobj\n"
    x=len(out); out+=b"xref\n0 %d\n0000000000 65535 f \n"%(len(objs)+1)+b"".join(b"%010d 00000 n \n"%o for o in offs)
    return out+b"trailer\n<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n"%(len(objs)+1,x)
P=b"<</Type/Pages/Kids[3 0 R]/Count 1>>";PG=b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>"
def st(d,e=b""): return b"<<%s/Length %d>>stream\n"%(e,len(d))+d+b"\nendstream"
def w(n,b): open(os.path.join(D,n),"wb").write(b)
w("names_js.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/Names<</JavaScript 4 0 R>>>>",P,PG,b"<</Names[(a) 5 0 R]>>",b"<</S/JavaScript/JS(app.alert(1))>>"]))
w("ff_sep.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction<<\x0c/S\x0c/JavaScript\x0c/JS\x0c(x)>>>>",P,PG]))
w("page_aa.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/AA<</O<</S/Launch/F(cmd.exe)>>>>>>"]))
w("lzw_objstm.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"0",st(b"\x80\x0b\x60\x50\x22\x0c\x0c\x85\x01",b"/Type/ObjStm/N 1/First 4/Filter/LZWDecode")]))
w("annot_js.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Annots[4 0 R]>>",b"<</Type/Annot/Subtype/Link/Rect[0 0 612 792]/A<</S/JavaScript/JS 5 0 R>>>>",st(b"app.alert(1)")]))
w("nested_objstm.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"0",st(zlib.compress(b"6 0 "+st(b"x")),b"/Type/ObjStm/N 1/First 4/Filter/FlateDecode"),b"0"]))
w("encrypt.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,PG]).replace(b"/Root 1 0 R",b"/Root 1 0 R/Encrypt<</Filter/Standard/V 1/R 2/O(x)/U(y)/P -4>>"))
w("ws_obj.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4\x000 R>>",P,PG,b"<</S/JavaScript/JS(1)>>"]))
