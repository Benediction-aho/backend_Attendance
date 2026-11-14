# Round-2 bypass samples for PdfActiveContentScanner. Usage: python3 phase5_pdf_bypass_r2.py OUTDIR
import zlib,sys,os,binascii,base64
D=sys.argv[1]
def pdf(objs,tail=b""):
    out=b"%PDF-1.7\n%\xe2\xe3\xcf\xd3\n";offs=[]
    for i,o in enumerate(objs,1):
        offs.append(len(out)); out+=b"%d 0 obj\n"%i+o+b"\nendobj\n"
    x=len(out); out+=b"xref\n0 %d\n0000000000 65535 f \n"%(len(objs)+1)+b"".join(b"%010d 00000 n \n"%o for o in offs)
    out+=b"trailer\n<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n"%(len(objs)+1,x); return out+tail
P=b"<</Type/Pages/Kids[3 0 R]/Count 1>>";PG=b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>"
def st(d,extra=b""): return b"<</Length %d%s>>stream\n"%(len(d),extra)+d+b"\nendstream"
def w(n,b): open(os.path.join(D,n),"wb").write(b)
JS=b"<</S/JavaScript/JS(app.alert(1))>>"
body=b"4 0 "+JS
# A: object stream with ASCIIHexDecode only
w("objstm_hex.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"0",st(binascii.hexlify(body)+b">",b"/Type/ObjStm/N 1/First 4/Filter/ASCIIHexDecode")]))
# B: object stream with [ASCII85 Flate] chain
a85=base64.a85encode(zlib.compress(body))+b"~>"
w("objstm_a85flate.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"0",st(a85,b"/Type/ObjStm/N 1/First 4/Filter[/ASCII85Decode/FlateDecode]")]))
# C: Flate with PNG predictor
raw=body; rows=b"".join(b"\x00"+raw[i:i+len(raw)] for i in range(0,len(raw),len(raw)))
w("objstm_pred.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"0",st(zlib.compress(rows),b"/Type/ObjStm/N 1/First 4/Filter/FlateDecode/DecodeParms<</Predictor 12/Columns %d>>"%len(raw))]))
# D: 'stream\n' inside a string literal hides the following dictionary keys (no endstream afterwards)
w("string_stream.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/Lang (en stream\n) /OpenAction<</S/JavaScript/JS(app.alert(1))>>>>",P,PG]).replace(b"endstream",b""))
# E: same, with an 'endstream' in a later string
w("string_stream2.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/Lang (a stream\n)/OpenAction<</S/JavaScript/JS(app.alert(1))>>/Foo (endstream)>>",P,PG]))
# F: auto URI via a chain of depth 5 (/Next)
w("chain5.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"5 0 R",b"6 0 R",b"7 0 R",b"8 0 R",b"<</S/URI/URI(http://evil.example)>>"]))
w("chain_next.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>",P,PG,b"<</S/GoTo/D[3 0 R/Fit]/Next 5 0 R>>",b"<</S/GoTo/D[3 0 R/Fit]/Next 6 0 R>>",b"<</S/GoTo/D[3 0 R/Fit]/Next 7 0 R>>",b"<</S/GoTo/D[3 0 R/Fit]/Next 8 0 R>>",b"<</S/URI/URI(http://evil.example)>>"]))
# G: incremental update adding JS
base=pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,PG])
w("incremental.pdf",base+b"1 0 obj\n<</Type/Catalog/Pages 2 0 R/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>\nendobj\n%%EOF\n")
# H: broken xref / no xref
w("broken_xref.pdf",b"%PDF-1.7\n1 0 obj<</Type/Catalog/Pages 2 0 R/OpenAction<</S/Java#53cript/JS(x)>>>>endobj\n2 0 obj"+P+b"endobj\ntrailer<</Root 1 0 R>>\n%%EOF")
# I: CR-only stream keyword followed by object with JS
w("hexstring_name.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R/Names<</JavaScript 4 0 R>>>>",P,PG,b"<</Names[(a) 5 0 R]>>",JS]))
# legit: large random image PDFs
for i in range(5):
    img=os.urandom(4*1024*1024)
    w("rand%d.pdf"%i,pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,PG,st(img,b"/Type/XObject/Subtype/Image/Width 1024/Height 1024/BitsPerComponent 8/ColorSpace/DeviceRGB/Filter/DCTDecode")]))
    w("randflate%d.pdf"%i,pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,PG,st(zlib.compress(os.urandom(3*1024*1024)),b"/Filter/FlateDecode")]))
w("link.pdf",pdf([b"<</Type/Catalog/Pages 2 0 R>>",P,b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Annots[<</Type/Annot/Subtype/Link/Rect[0 0 9 9]/A<</S/URI/URI(https://x.example)>>>>]>>"]))
